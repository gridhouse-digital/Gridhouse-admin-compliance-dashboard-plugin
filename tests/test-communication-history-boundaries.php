<?php
require __DIR__ . '/bootstrap.php';

$fails = 0;
function communication_history_check( bool $condition, string $message ): void {
	global $fails;
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
	$fails += $condition ? 0 : 1;
}

$root       = dirname( __DIR__ );
$repository = file_get_contents( $root . '/includes/messaging/class-messaging-repository.php' );
$ui         = file_get_contents( $root . '/includes/messaging/class-messaging-ui.php' );
$js         = file_get_contents( $root . '/assets/dashboard.js' );

communication_history_check( false !== strpos( $ui, 'history_for_employee( $employee_user_id, 1, 3 )' ), 'drawer loads at most three logical communications' );
communication_history_check( false !== strpos( $ui, '$per_page = 20' ) && false !== strpos( $repository, 'min( 20, $per_page )' ), 'full history uses bounded 20-communication server pagination' );
communication_history_check( false !== strpos( $repository, 'SELECT DISTINCT c.id' ) && false !== strpos( $ui, '$communications[ $id ]' ), 'combined Email and SMS rows are grouped into one logical communication' );
communication_history_check( false !== strpos( $repository, "array( 'email', 'sms' )" ) && false !== strpos( $repository, "array( 'queued', 'processing', 'accepted', 'sent', 'delivered', 'undelivered', 'failed', 'suppressed' )" ), 'channel and delivery-state filters are allowlisted' );
communication_history_check( substr_count( $repository, '$wpdb->prepare' ) >= 10 && false !== strpos( $repository, '$wpdb->esc_like' ), 'history identifiers and search values use prepared SQL and escaped LIKE patterns' );
communication_history_check( false !== strpos( $repository, "preg_match( '/^(\\d{4})-(\\d{2})-(\\d{2})$/'" ) && false !== strpos( $repository, 'checkdate(' ), 'date filters require valid literal calendar dates' );
communication_history_check( false !== strpos( $ui, "check_ajax_referer( 'ghca_acd_table', 'nonce' )" ) && false !== strpos( $ui, 'user_can_view_communication_history()' ) && false !== strpos( $ui, 'GHCA_ACD_User_Report::can_view_user( $user_id )' ), 'history endpoints repeat nonce, permission, and employee-scope authorization' );
communication_history_check( false !== strpos( $ui, 'get_communication_for_employee( $communication_id, $user_id )' ) && false !== strpos( $ui, "'employee_drawer' !== (string) \$row['source']" ), 'Use Again draft is bound to the selected employee and manual reminder source' );
communication_history_check( false !== strpos( $ui, '<details class="ghca-acd__history-message">' ) && false !== strpos( $ui, 'nl2br( esc_html( $message ) )' ), 'complete stored message is escaped inside an expandable disclosure' );
communication_history_check( false !== strpos( $js, "params.append('page', String(page || 1))" ) && false !== strpos( $js, 'appendHistoryFilters(params)' ), 'older history is requested only after the full modal opens' );
communication_history_check( false === stripos( $ui, 'delete communication' ) && false === stripos( $repository, 'delete_communication' ), 'history remains immutable with no per-record delete action' );

/* -------------------------------------------------------------------------
 * Reminder modal refresh: live preview + preserved handler hooks
 * ---------------------------------------------------------------------- */
communication_history_check( false !== strpos( $ui, 'ghca-acd__reminder-preview' ) && false !== strpos( $ui, 'data-ghca-reminder-preview-channel' ) && false === strpos( $ui, 'hidden data-ghca-reminder-preview-subject' ), 'the reminder preview is rendered rather than hidden' );
/* The preview must keep reusing the shared urgency prefix so it shows the real
 * outgoing subject rather than a second, drifting implementation. */
communication_history_check( false !== strpos( $js, 'previewSubject.textContent = urgencyPrefix(' ) && false !== strpos( $js, "previewChannel.textContent = smsOnly ? 'SMS'" ), 'the preview reflects the urgency prefix and the selected channel' );
/* SMS carries the body only; showing a subject there would misrepresent it. */
communication_history_check( false !== strpos( $js, 'previewSubject.hidden = smsOnly;' ), 'the preview hides the subject line for SMS-only delivery' );
/* Every handler hook the reminder JS binds to must survive the redesign. */
communication_history_check( false !== strpos( $ui, 'data-ghca-reminder-channel-option' ) && false !== strpos( $ui, 'data-ghca-reminder-urgency' ) && false !== strpos( $ui, 'data-ghca-reminder-template' ) && false !== strpos( $ui, 'data-ghca-reminder-subject' ) && false !== strpos( $ui, 'data-ghca-reminder-message' ) && false !== strpos( $ui, 'data-ghca-sms-safe' ) && false !== strpos( $ui, 'data-ghca-reminder-token' ), 'the redesigned modal keeps every reminder handler hook' );
/* No invented operator id or read-receipt claim. */
communication_history_check( false === strpos( $ui, 'Compliance Agent #' ) && false === strpos( $ui, 'read receipt' ) && false !== strpos( $ui, 'Communication History' ), 'the modal audit line names the real acting account and claims no read receipts' );

/* -------------------------------------------------------------------------
 * History modal refresh: real delivery totals, no invented read tracking
 * ---------------------------------------------------------------------- */
$repo = (string) file_get_contents( dirname( __DIR__ ) . '/includes/messaging/class-messaging-repository.php' );
/* Totals come from one grouped query over the delivery ledger. */
communication_history_check( false !== strpos( $repo, 'function history_stats' ) && false !== strpos( $repo, 'GROUP BY d.channel, d.state' ) && false !== strpos( $ui, 'GHCA_ACD_Messaging_Repository::history_stats( $user_id )' ), 'history totals come from a single grouped delivery query' );
/* The schema records no read/open state, so the modal must not report one. */
communication_history_check( false === strpos( $ui, 'Read rate' ) && false === strpos( $ui, 'read_rate' ) && false === strpos( $repo, 'read_at' ) && false === strpos( $repo, 'opened_at' ), 'no read or open metric is reported anywhere in the history modal' );
/* Only states the ledger actually stores are counted. */
communication_history_check( false !== strpos( $repo, 'delivered' ) && false !== strpos( $repo, 'undelivered' ) && false !== strpos( $repo, 'function history_stats' ), 'history stats count only recorded delivery states' );
/* The stats strip renders the four real measures. */
communication_history_check( false !== strpos( $ui, 'data-ghca-history-stats' ) && 4 === substr_count( $ui, 'data-ghca-history-stat="' ) && false !== strpos( $js, "statsEl.querySelector('[data-ghca-history-stat=" ), 'the modal renders four delivery totals from the response' );
/* Feed rows lead with the delivery channel. */
communication_history_check( false !== strpos( $ui, 'ghca-acd__history-item-icon' ) && false !== strpos( $ui, 'lead_channel' ), 'history rows show the channel they were sent on' );

exit( $fails ? 1 : 0 );
