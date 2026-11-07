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

// Helpers that read the store itself, not the response text.
$dly   = static function ( int $emp ): array { return ghca_bulk_delivery_for( $emp ) ?? array(); };
$jobs  = static function ( int $emp ) use ( $dly ): int { $d = $dly( $emp ); return $d ? ghca_bulk_jobs_for( (int) $d['id'] ) : 0; };
$ncomm = static function (): int { return count( $GLOBALS['ghca_bulk']['communications'] ); };
$send_ids = static function ( string $ids, string $token ) use ( $send ): array {
	$r = ghca_bulk_ajax( 'ghca_acd_bulk_message_send', array( 'reviewed' => '1', 'ids' => $ids, 'token' => $token ) + $send );
	return array( by_id( $r['payload']['data']['results'] ), $r['payload']['data']['counts'] );
};
$zero = array_fill_keys( GHCA_ACD_Bulk_Messaging::STATUSES, 0 );

// ---- A. Mixed batch; one recipient's job cannot be scheduled the first time (persisted, then scheduling fails).
$GLOBALS['ghca_bulk']['schedule_fail'] = array( 306 => 1 );
list( $res, $counts ) = $send_ids( '301,302,306,307', $token );
check( 'queued' === $res[301]['status'] && 'queued' === $res[302]['status'] && 'queued' === $res[307]['status'], 'A: ready recipients queued' );
check( 'unscheduled' === $res[306]['status'] && str_contains( $res[306]['message'], 'Nothing was sent' ), 'A: saved-but-unscheduled is reported as such, not as queued or sent' );
check( array( 'queued' => 3, 'unscheduled' => 1 ) + $zero == $counts, 'A: totals 3 queued, 1 saved but not scheduled' );
check( 4 === $ncomm(), 'A: one message record per recipient, including the unscheduled one' );
$d306 = $dly( 306 );
check( 'failed' === $d306['state'] && 'queue_schedule_failed' === $d306['last_error_code'] && 0 === $d306['attempt_count'] && 0 === $jobs( 306 ), 'A: the unscheduled delivery is failed/queue_schedule_failed, never attempted, with no job' );
check( 1 === $jobs( 301 ) && 1 === $jobs( 302 ) && 1 === $jobs( 307 ) && 3 === count( $GLOBALS['ghca_bulk']['scheduled'] ), 'A: exactly one job for each queued recipient; three jobs in all' );
$comms = ghca_bulk_comms();
$to = array();
foreach ( $comms as $c ) {
	$to[] = (int) $c['employee_user_id'];
	check( 1 === count( $c['deliveries'] ) && 'email' === $c['deliveries'][0]['channel'], 'employee ' . $c['employee_user_id'] . ': exactly one email delivery, no SMS' );
}
check( array( 301, 302, 306, 307 ) === $to, 'A: messages only for the ids sent, in order' );
check( str_contains( $comms[0]['message'], 'Hi Daniel,' ) && str_contains( $comms[1]['message'], 'Hi Maya,' ) && ! str_contains( $comms[0]['message'], 'Maya' ), 'A: each stored message is personalised; none names another recipient' );
check( 'Training overdue, Ivy' === $comms[3]['subject'], 'A: names are data: the subject carries the first name only' );
check( 3 === $comms[0]['template_version'] && 1 === $comms[0]['template_id'], 'A: the template and its version are recorded' );
$msg306 = $comms[2]['message'];

// ---- B. Successful recovery: retry reuses the same record and schedules it once.
list( $res, $counts ) = $send_ids( '306', $token );
$after = $dly( 306 );
check( 'recovered' === $res[306]['status'] && array( 'recovered' => 1 ) + $zero == $counts, 'B: retry recovers the saved message' );
check( $after['id'] === $d306['id'] && 'queued' === $after['state'] && null === $after['last_error_code'] && 0 === $after['attempt_count'], 'B: the same delivery record, back to queued through requeue_failed, error cleared, not attempted' );
check( 1 === $jobs( 306 ) && 4 === count( $GLOBALS['ghca_bulk']['scheduled'] ), 'B: exactly one job now exists for it' );
check( 4 === $ncomm() && ghca_bulk_comms()[2]['message'] === $msg306, 'B: no new message record; the personalised message is unchanged' );

// ---- C. Retrying again, or resubmitting the whole batch, changes nothing.
list( $res, $counts ) = $send_ids( '306', $token );
check( 'pending' === $res[306]['status'] && 1 === $jobs( 306 ), 'C: a second retry reports already queued and adds no job' );
list( $res, $counts ) = $send_ids( '301,302,306,307', $token );
check( array( 'pending' => 4 ) + $zero == $counts && 4 === $ncomm() && 4 === count( $GLOBALS['ghca_bulk']['scheduled'] ), 'C: resubmitting the batch: all four already queued; still four records and four jobs' );

// ---- D. Repeated scheduling failure: every retry reports it honestly and leaves no job.
$t2 = wp_generate_uuid4();
$GLOBALS['ghca_bulk']['schedule_fail'] = array( 306 => -1 );
list( $res ) = $send_ids( '306', $t2 );
$first = $dly( 306 ); // the newest record for 306 is the first match only if we look it up by batch; find it:
$batch2 = array_values( array_filter( $GLOBALS['ghca_bulk']['deliveries'], static function ( $d ) { return 306 === $d['employee_user_id'] && 'failed' === $d['state']; } ) );
check( 'unscheduled' === $res[306]['status'] && 1 === count( $batch2 ), 'D: a new batch for 306 is saved but cannot be scheduled' );
for ( $i = 0; $i < 3; $i++ ) {
	list( $res, $counts ) = $send_ids( '306', $t2 );
	$row = $GLOBALS['ghca_bulk']['deliveries'][ $batch2[0]['id'] ];
	check( 'unscheduled' === $res[306]['status'] && str_contains( $res[306]['message'], 'still could not be scheduled' ) && 'failed' === $row['state'] && 'queue_schedule_failed' === $row['last_error_code'] && 0 === $row['attempt_count'] && 0 === ghca_bulk_jobs_for( $row['id'] ), "D: retry $i: still unscheduled, back to failed, never attempted, no job" );
}
check( 5 === $ncomm(), 'D: repeated retries never create another message (five records: four from batch 1, one from batch 2)' );
$GLOBALS['ghca_bulk']['schedule_fail'] = array();

// ---- E. Simultaneous retries: the scheduling lock and the compare-and-set allow one job at most.
$did = $batch2[0]['id'];
GHCA_ACD_Messaging_Repository::acquire_schedule_lock( $did ); // another request is scheduling it now
list( $res ) = $send_ids( '306', $t2 );
check( 'busy' === $res[306]['status'] && 0 === ghca_bulk_jobs_for( $did ) && 'failed' === $GLOBALS['ghca_bulk']['deliveries'][ $did ]['state'], 'E: while another request holds the lock: reported busy, nothing scheduled, state untouched' );
GHCA_ACD_Messaging_Repository::release_schedule_lock( $did );
// The other request requeued it but died before scheduling: queued with no job.
GHCA_ACD_Messaging_Repository::requeue_failed( $did );
check( false === GHCA_ACD_Messaging_Repository::requeue_failed( $did ), 'E: requeue_failed is compare-and-set: a second requeue of the same delivery is refused' );
list( $a1 ) = $send_ids( '306', $t2 );
list( $a2 ) = $send_ids( '306', $t2 );
check( 'recovered' === $a1[306]['status'] && 'pending' === $a2[306]['status'] && 1 === ghca_bulk_jobs_for( $did ), 'E: queued-without-job is scheduled once; the next request sees the job and adds none' );
check( false === wp_schedule_single_event( time() + 1, 'ghca_acd_process_message_delivery', array( $did ) ) && true === GHCA_ACD_Messaging_Worker::schedule( $did ) && 1 === ghca_bulk_jobs_for( $did ), 'E: a racing schedule call finds the existing job: core refuses the duplicate and the worker treats it as scheduled, so it is never marked failed' );

// ---- F. Lost responses.
$t3 = wp_generate_uuid4();
$send_ids( '302', $t3 ); // saved and scheduled; pretend the browser never got the answer
$d302 = array_values( array_filter( $GLOBALS['ghca_bulk']['deliveries'], static function ( $d ) { return 302 === $d['employee_user_id']; } ) );
$lost = end( $d302 );
list( $res ) = $send_ids( '302', $t3 );
check( 'pending' === $res[302]['status'] && 1 === ghca_bulk_jobs_for( $lost['id'] ) && 6 === $ncomm(), 'F: response lost after scheduling: the retry reports already queued; no new record or job' );
$t4 = wp_generate_uuid4();
$GLOBALS['ghca_bulk']['schedule_fail'] = array( 307 => -1 );
$send_ids( '307', $t4 );
$GLOBALS['ghca_bulk']['schedule_fail'] = array();
$d307 = array_values( array_filter( $GLOBALS['ghca_bulk']['deliveries'], static function ( $d ) { return 307 === $d['employee_user_id']; } ) );
$died = end( $d307 );
GHCA_ACD_Messaging_Repository::force( $died['id'], array( 'state' => 'queued', 'last_error_code' => null ) ); // the request died after saving, before scheduling
list( $res ) = $send_ids( '307', $t4 );
check( 'recovered' === $res[307]['status'] && 1 === ghca_bulk_jobs_for( $died['id'] ) && 7 === $ncomm(), 'F: request died between saving and scheduling: the retry schedules that same record once' );

// ---- G. Messages already processing, sent, or failed after an attempt are never reset or resent.
$t5 = wp_generate_uuid4();
$send_ids( '301,302,307', $t5 );
$byemp = array();
foreach ( $GLOBALS['ghca_bulk']['deliveries'] as $d ) { $byemp[ $d['employee_user_id'] ] = $d['id']; } // newest per employee
foreach ( array( 301, 302, 307 ) as $e ) { ghca_bulk_job_ran( $byemp[ $e ] ); } // each job has run: the worker took it
GHCA_ACD_Messaging_Repository::claim_delivery( $byemp[301] );                                                    // a worker is sending it
GHCA_ACD_Messaging_Repository::force( $byemp[302], array( 'state' => 'accepted', 'attempt_count' => 1 ) );      // already sent
GHCA_ACD_Messaging_Repository::force( $byemp[307], array( 'state' => 'failed', 'attempt_count' => 1, 'last_error_code' => 'worker_interrupted', 'last_error_summary' => 'The background email worker was interrupted.' ) );
$jobsBefore = count( $GLOBALS['ghca_bulk']['scheduled'] );
list( $res, $counts ) = $send_ids( '301,302,307', $t5 );
check( 'processing' === $res[301]['status'] && 'processing' === $GLOBALS['ghca_bulk']['deliveries'][ $byemp[301] ]['state'] && 1 === $GLOBALS['ghca_bulk']['deliveries'][ $byemp[301] ]['attempt_count'], 'G: processing: reported, left alone' );
check( 'sent' === $res[302]['status'] && 'accepted' === $GLOBALS['ghca_bulk']['deliveries'][ $byemp[302] ]['state'], 'G: already sent: reported, never sent again' );
check( 'delivery_failed' === $res[307]['status'] && 'failed' === $GLOBALS['ghca_bulk']['deliveries'][ $byemp[307] ]['state'] && 'worker_interrupted' === $GLOBALS['ghca_bulk']['deliveries'][ $byemp[307] ]['last_error_code'], 'G: an ambiguous outcome (worker interrupted mid-send) is not requeued; it is left for review in Communication History' );
check( array( 'processing' => 1, 'sent' => 1, 'delivery_failed' => 1 ) + $zero == $counts, 'G: totals report each state; none counted as queued' );
check( count( $GLOBALS['ghca_bulk']['scheduled'] ) === $jobsBefore && 10 === $ncomm(), 'G: no job and no record created for any of them' );

// ---- H. One mixed retry with every state at once.
$t6 = wp_generate_uuid4();
$GLOBALS['ghca_bulk']['schedule_fail'] = array( 306 => 1 );
list( $res ) = $send_ids( '301,302,306,307', $t6 );
$by6 = array();
foreach ( $GLOBALS['ghca_bulk']['deliveries'] as $d ) { $by6[ $d['employee_user_id'] ] = $d['id']; }
ghca_bulk_job_ran( $by6[302] );
ghca_bulk_job_ran( $by6[307] );
GHCA_ACD_Messaging_Repository::force( $by6[302], array( 'state' => 'delivered', 'attempt_count' => 1 ) );
GHCA_ACD_Messaging_Repository::force( $by6[307], array( 'state' => 'failed', 'attempt_count' => 1, 'last_error_code' => 'wp_mail_failed', 'last_error_summary' => 'The mail server refused the message.' ) );
list( $res, $counts ) = $send_ids( '301,302,303,306,307', $t6 );
check( 'pending' === $res[301]['status'] && 'sent' === $res[302]['status'] && 'failed' === $res[303]['status'] && 'recovered' === $res[306]['status'] && 'delivery_failed' === $res[307]['status'], 'H: one retry, five different outcomes, each exact' );
check( array( 'pending' => 1, 'sent' => 1, 'failed' => 1, 'recovered' => 1, 'delivery_failed' => 1 ) + $zero == $counts, 'H: totals match the outcomes; only pending and recovered have jobs' );
check( 1 === ghca_bulk_jobs_for( $by6[301] ) && 0 === ghca_bulk_jobs_for( $by6[302] ) && 1 === ghca_bulk_jobs_for( $by6[306] ) && 0 === ghca_bulk_jobs_for( $by6[307] ) && 14 === $ncomm(), 'H: jobs exactly where the totals say; no new records (303 was refused before saving)' );

// ---- I. Recovery keeps every gate.
$GLOBALS['ghca_bulk']['can_remind'] = false;
$r = GHCA_ACD_Messaging_Service::recover_delivery( $by6[301] );
check( is_wp_error( $r ) && 'ghca_message_permission_denied' === $r->get_error_code(), 'I: recovery needs the send-reminders permission' );
$GLOBALS['ghca_bulk']['can_remind'] = true;
$GLOBALS['ghca_bulk_people'][306][2] = false; // 306 moved out of this viewer's scope
ghca_bulk_job_ran( $by6[306] );
GHCA_ACD_Messaging_Repository::force( $by6[306], array( 'state' => 'failed', 'last_error_code' => 'queue_schedule_failed' ) ); // its job was lost
$r = GHCA_ACD_Messaging_Service::recover_delivery( $by6[306] );
check( is_wp_error( $r ) && 'ghca_message_employee_out_of_scope' === $r->get_error_code() && 'failed' === $GLOBALS['ghca_bulk']['deliveries'][ $by6[306] ]['state'], 'I: recovery re-checks agency scope; an out-of-scope record is not touched or described' );
list( $res ) = $send_ids( '306', $t6 );
check( 'failed' === $res[306]['status'] && 'Invalid employee or permission denied.' === $res[306]['message'], 'I: through the bulk endpoint the same refusal, with no state disclosed' );
$GLOBALS['ghca_bulk_people'][306][2] = true;
$GLOBALS['ghca_bulk']['email_on'] = false;
$r = GHCA_ACD_Messaging_Service::recover_delivery( $by6[306] );
check( is_wp_error( $r ) && 'ghca_message_email_not_ready' === $r->get_error_code() && 0 === ghca_bulk_jobs_for( $by6[306] ), 'I: email readiness re-checked before scheduling' );
$GLOBALS['ghca_bulk']['email_on'] = true;

// ---- J. A new batch token is a deliberate new message.
list( $res ) = $send_ids( '301', wp_generate_uuid4() );
check( 'queued' === $res[301]['status'] && 15 === $ncomm(), 'J: a new batch (new token) is a new message, as a deliberate second send' );

// ---- K. Directly posted ids that are not ready or out of scope are refused one by one.
list( $res ) = $send_ids( '303,304,305', $token );
check( 'failed' === $res[303]['status'] && 'failed' === $res[304]['status'] && 'failed' === $res[305]['status'] && 15 === $ncomm(), 'K: not-ready or out-of-scope ids posted directly are refused by the service' );

// ---- Sender rate limit: the service's own limit, reported per recipient.
ghca_bulk_reset();
$GLOBALS['ghca_bulk']['actor_count'] = 29;
$r   = ghca_bulk_ajax( 'ghca_acd_bulk_message_send', array( 'reviewed' => '1', 'token' => wp_generate_uuid4(), 'ids' => '301,302' ) + $send );
$res = by_id( $r['payload']['data']['results'] );
check( 'queued' === $res[301]['status'] && 'failed' === $res[302]['status'] && str_contains( $res[302]['message'], 'Too many reminders' ), 'at the sender limit the rest are reported as not sent, not silently dropped' );

// ---- The in-memory repository mirrors the real one; the real SQL must carry the same guards.
$repo = (string) file_get_contents( __DIR__ . '/../includes/messaging/class-messaging-repository.php' );
$fn   = static function ( string $name ) use ( $repo ): string { $i = strpos( $repo, 'function ' . $name . '(' ); return false === $i ? '' : substr( $repo, $i, 1400 ); };
check( str_contains( $fn( 'mark_schedule_failed' ), 'WHERE id = %d AND state = %s' ) && str_contains( $fn( 'mark_schedule_failed' ), "'queued'" ) && str_contains( $fn( 'mark_schedule_failed' ), '1 !== (int) $updated' ), 'real mark_schedule_failed is compare-and-set on queued: it cannot overwrite processing or sent' );
check( str_contains( $fn( 'requeue_failed' ), 'WHERE id = %d AND state = %s AND attempt_count < %d' ), 'real requeue_failed is compare-and-set on failed, bounded by the attempt ceiling' );
check( str_contains( $fn( 'claim_delivery' ), 'WHERE id = %d AND state = %s AND attempt_count < %d' ), 'real claim_delivery is compare-and-set on queued: two jobs for one delivery still send once' );
check( str_contains( $fn( 'acquire_schedule_lock' ), 'INSERT IGNORE INTO' ) && str_contains( $fn( 'acquire_schedule_lock' ), 'AND option_value = %s' ), 'real scheduling lock: atomic insert, stale takeover is itself compare-and-set' );
$worker = (string) file_get_contents( __DIR__ . '/../includes/messaging/class-messaging-worker.php' );
check( str_contains( $worker, 'return self::is_scheduled( $delivery_id );' ), 'real worker: a refused duplicate event counts as scheduled' );

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
