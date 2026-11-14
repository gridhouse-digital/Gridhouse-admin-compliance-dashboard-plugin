<?php
/**
 * The redesigned Send reminder modal. It must bind to exactly the hooks the legacy modal has, so the messaging
 * script, its context, template, preview, readiness, consent and send calls are the existing ones, and it must
 * appear only for people who can send reminders.
 */
require __DIR__ . '/fixtures/redesign-drawer-env.php';

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
function hooks( string $html ): array {
	preg_match_all( '/\b(data-ghca-[a-z-]*(?:reminder|sms)[a-z-]*)/', $html, $m );
	$h = array_values( array_unique( $m[1] ) );
	sort( $h );
	return $h;
}

// The legacy modal's reminder block: the source text between its opening and closing conditionals.
$source = (string) file_get_contents( __DIR__ . '/../includes/messaging/class-messaging-ui.php' );
$start  = strpos( $source, 'id="ghca-acd-reminder-modal"' );
$end    = strpos( $source, 'id="ghca-acd-history-modal"' );
$legacy = substr( $source, (int) $start, (int) $end - (int) $start );
$legacy_hooks = array_values( array_filter( hooks( $legacy ), static function ( $h ) { return false === strpos( $h, 'history' ); } ) );

ghca_env_reset( array( 'can_remind' => true ) );
$board = GHCA_ACD_Redesign_Reminder::modal_html();
$board_hooks = hooks( $board );

$missing = array_values( array_diff( $legacy_hooks, $board_hooks ) );
check( array() === $missing, 'every data-ghca reminder hook of the legacy modal is in the redesigned modal; missing: ' . implode( ', ', $missing ) );
check( false !== strpos( $board, 'id="ghca-acd-reminder-modal"' ) && 1 === substr_count( $board, 'id="ghca-acd-reminder-modal"' ), 'one modal with the id the script looks up' );
check( false !== strpos( $board, 'data-ghca-ui="board"' ), 'it marks itself as the board modal so the script offers the confirm step' );
check( false !== strpos( $board, 'hidden aria-hidden="true"' ), 'it starts hidden' );
check( 0 === substr_count( $board, 'ghca-acd__' ), 'no legacy class names' );
check( 3 === substr_count( $board, 'data-ghca-reminder-channel-option' ), 'three delivery methods: email, SMS, both' );
foreach ( array( 'email', 'sms', 'email_sms' ) as $v ) {
	check( false !== strpos( $board, 'name="ghca-acd-reminder-channel" value="' . $v . '"' ), "delivery method value $v matches the existing script" );
}
foreach ( array( 'normal', 'important', 'urgent' ) as $v ) {
	check( false !== strpos( $board, '<option value="' . $v . '">' ), "urgency $v is offered: the plugin's own three levels" );
}
check( false !== strpos( $board, 'maxlength="4000"' ) && false !== strpos( $board, 'maxlength="200"' ), 'the existing subject and message limits are kept' );
check( false !== strpos( $board, 'role="alertdialog"' ) && false !== strpos( $board, 'role="dialog"' ), 'the compose step is a dialog and the confirm step an alert dialog' );
check( false !== strpos( $board, 'data-ghca-reminder-confirm hidden' ), 'the confirm step starts hidden' );
check( false !== strpos( $board, 'form="ghca-rm-form"' ) && false !== strpos( $board, 'id="ghca-rm-form"' ), 'the footer submit button belongs to the form' );
check( false !== strpos( $board, 'novalidate' ), 'validation messages stay inline, as before' );
check( false !== strpos( $board, 'I confirm this SMS contains no medical, certificate, detailed course, password, or other sensitive employee information.' ), 'the SMS consent wording is the existing wording, word for word' );
check( false !== strpos( $board, 'Every reminder is recorded in this employee’s Communication History with its delivery status.' ), 'the record-keeping note says only what the system does' );
check( false === stripos( $board, 'opened' ) && false === stripos( $board, 'read receipt' ), 'it makes no claim about the recipient opening the message' );
check( false === strpos( $board, 'Merge fields' ) && false === stripos( $board, 'bulk' ), 'no merge-field hint and no bulk controls: the plugin has neither' );

// Gating: the same capability the legacy modal uses.
ghca_env_reset( array( 'can_remind' => false ) );
check( '' === GHCA_ACD_Redesign_Reminder::modal_html(), 'nothing is rendered without the reminder capability' );

// The legacy footer modal still prints for the legacy dashboard, and steps aside for the redesigned one.
check( false !== strpos( $source, '! GHCA_ACD_Redesign::dashboard_rendered()' ), 'the footer modal is skipped only when the redesigned dashboard has printed its own' );
check( 1 === preg_match( '/\\$board = [^;]*GHCA_ACD_Redesign_Reminder::modal_html/', (string) file_get_contents( __DIR__ . '/../includes/class-redesign.php' ) ) || false !== strpos( (string) file_get_contents( __DIR__ . '/../includes/class-redesign.php' ), 'GHCA_ACD_Redesign_Reminder::modal_html()' ), 'the redesigned dashboard prints the modal beside the drawer' );

if ( $failed ) {
	fwrite( STDERR, "$failed of $checks checks failed\n" );
	exit( 1 );
}
echo "PASS: $checks reminder modal checks\n";
