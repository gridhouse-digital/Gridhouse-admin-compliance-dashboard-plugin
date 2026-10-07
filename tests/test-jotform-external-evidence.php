<?php
require __DIR__ . '/bootstrap.php';

function remove_accents( string $value ): string { return $value; }
function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $value ) ); }

require_once __DIR__ . '/../includes/jotform/class-jotform-repository.php';

$fails = 0;
function jotform_check( bool $condition, string $message ): void {
	global $fails;
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
	$fails += $condition ? 0 : 1;
}

/**
 * Returns the source of one method, so "X happens before Y" assertions measure the
 * method that owns the behaviour rather than the first match anywhere in the file.
 */
function jotform_method_body( string $source, string $signature ): string {
	$start = strpos( $source, $signature );
	if ( false === $start ) {
		return '';
	}
	$end = strpos( $source, "\n\tpublic static function ", $start + strlen( $signature ) );
	if ( false === $end ) {
		$end = strpos( $source, "\n\tprivate static function ", $start + strlen( $signature ) );
	}
	return false === $end ? substr( $source, $start ) : substr( $source, $start, $end - $start );
}

$root = dirname( __DIR__ );
$main = file_get_contents( $root . '/gridhouse-admin-compliance-dashboard.php' );
$schema = file_get_contents( $root . '/includes/jotform/class-jotform-schema.php' );
$provider = file_get_contents( $root . '/includes/jotform/class-jotform-provider.php' );
$sync = file_get_contents( $root . '/includes/jotform/class-jotform-sync.php' );
$repository = file_get_contents( $root . '/includes/jotform/class-jotform-repository.php' );
$store = file_get_contents( $root . '/includes/jotform/class-external-evidence-store.php' );
$ui = file_get_contents( $root . '/includes/jotform/class-jotform-ui.php' );
$calculator = file_get_contents( $root . '/includes/class-audit-calculator.php' );
$pdf = file_get_contents( $root . '/includes/class-audit-pdf.php' );

jotform_check( false !== strpos( $main, 'Version: 1.7.1' ), 'release is versioned as 1.7.1' );
jotform_check( false === strpos( $main . $provider . $ui, 'get_user_jotform_submission' ), 'existing custom snippet AJAX action is not registered or modified' );
jotform_check( false !== strpos( $provider, "'APIKEY' => \$key" ) && false === strpos( $provider, 'apiKey=' ), 'Jotform credential is sent in a server-side header, not a query string' );
jotform_check( false === strpos( $ui, 'JOTFORM_API_KEY' ) || false !== strpos( $ui, 'wp-config.php' ), 'plugin UI does not persist a second Jotform credential' );
jotform_check( false !== strpos( $provider, "'standard'" ) && false !== strpos( $provider, "'eu'" ), 'Standard and EU API regions are supported' );
jotform_check( false !== strpos( $provider, 'QUOTA_FLOOR            = 100' ) && false !== strpos( $sync, 'quota_paused()' ), 'scheduled synchronization honors the 100-call safety floor' );
jotform_check( false !== strpos( $sync, 'verified_ownership' ) && false !== strpos( $sync, 'hash_hmac' ) && false !== strpos( $sync, 'ownership_claim_missing' ) && false === strpos( $sync, '$user_id = $email' ), 'automatic employee linking requires a signed form-bound ownership claim and never falls back to email' );
jotform_check( false !== strpos( $sync, "'identity_mismatch'" ), 'optional name or email differences are retained as review warnings' );
jotform_check( false !== strpos( $ui, 'GHCA_ACD_Jotform_Repository::form' ) && false !== strpos( $repository, 'hash_equals' ), 'document access resolves only administrator-allowlisted forms' );
jotform_check( false !== strpos( $schema, 'ghca_acd_jotform_documents' ) && false !== strpos( $schema, 'ghca_acd_external_course_catalog' ) && false !== strpos( $schema, 'ghca_acd_external_training' ) && false !== strpos( $schema, 'ghca_acd_external_evidence' ) && false !== strpos( $schema, 'ghca_acd_evidence_events' ), 'all versioned document, catalog, training, evidence and event tables are declared' );
jotform_check( false !== strpos( $schema, 'const VERSION        = 6' ) && false !== strpos( $schema, 'evidence_reference varchar(128)' ) && false !== strpos( $schema, 'quarantine_legacy_unsigned_rows' ) && false !== strpos( $schema, 'oltl_requirements text NULL' ) && false !== strpos( $schema, 'manual_manifest longtext NULL' ) && false !== strpos( $schema, "'entered_by', 'entry_reason', 'manual_manifest'" ), 'schema version 6 retains legacy quarantine data, OLTL mappings and adds verified manual-entry columns' );
jotform_check( false !== strpos( $ui, 'ghca_acd_jotform_manual_upload' ) && false !== strpos( $ui, 'prepare_manual_upload' ) && false !== strpos( $ui, 'verify_stored_manifest' ) && false !== strpos( $ui, 'GHCA_ACD_Jotform_Repository::approve( $id, $snapshot, $manifest' ) && false === strpos( $ui, 'approve_manual' ), 'manual entries are stored pending and approved only through the shared approve() transaction' );
jotform_check( false !== strpos( $repository, "const MANUAL_FORM_ID = 'manual'" ) && false !== strpos( $repository, 'manual_fingerprint_exists' ) && false !== strpos( $repository, "status='pending' AND approved_revision_id IS NULL" ), 'manual rows use a reserved form id, block identical re-uploads and can only be deleted while unfinished' );
jotform_check( false !== strpos( $schema, 'required_columns_exist' ) && false !== strpos( $schema, 'SHOW COLUMNS FROM {$table} LIKE %s' ) && false !== strpos( $schema, 'if ( ! self::required_columns_exist() )' ), 'schema version cannot advance until the required physical columns are verified' );
jotform_check( 5 === substr_count( $schema, ') ENGINE=InnoDB {$collate};' ) && false !== strpos( $schema, 'ensure_transactional_tables' ), 'all workflow tables require and verify transactional InnoDB storage' );
jotform_check( false !== strpos( $store, 'GHCA_ACD_EVIDENCE_ENCRYPTION_KEY' ) && false === strpos( $store, 'JOTFORM_API_KEY' ), 'evidence encryption uses a dedicated stable host secret' );
jotform_check( false !== strpos( $store, "'aes-256-gcm'" ) && false !== strpos( $store, "hash( 'sha256', \$plain )" ), 'approved evidence uses authenticated encryption and SHA-256 integrity records' );
jotform_check( false !== strpos( $store, 'GHCA_ACD_PRIVATE_DIR' ) && false !== strpos( $store, 'outside WordPress and uploads' ), 'approved evidence requires explicit durable private storage outside web-readable paths' );
jotform_check( false !== strpos( $provider, 'MAX_DOCUMENT_BYTES     = 26214400' ) && false !== strpos( $provider, 'wp_safe_remote_get' ) && false !== strpos( $provider, 'host_resolves_publicly' ), 'remote file access enforces size, safe HTTP and DNS boundaries' );
jotform_check( false !== strpos( $repository, 'issue_document_grant' ) && false !== strpos( $repository, 'consume_document_grant' ) && false !== strpos( $repository, 'DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s' ), 'document preview and download use short-lived compare-and-delete one-use grants' );
jotform_check( false !== strpos( $ui, 'documents_for_training' ) && false !== strpos( $ui, 'training_document_matches' ) && false !== strpos( $ui, 'data-training-id' ), 'reviewers can inspect every certificate through a token bound to the exact pending training revision' );
jotform_check( false !== strpos( $store, 'ghca_evidence_source_changed' ) && false !== strpos( $sync, 'submission_fingerprint' ), 'approval fails closed when the indexed Jotform submission has changed' );
jotform_check( false !== strpos( $calculator, "if ( 'annual' === \$tracker_type" ) && false !== strpos( $calculator, "'source' => 'external'" ), 'approved external lessons are added only to annual audit calculations' );
jotform_check( false !== strpos( $pdf, 'evidence_revision_id' ) && false !== strpos( $pdf, 'snapshot_digest' ) && false !== strpos( $pdf, 'materialize_external_evidence' ), 'packet jobs bind external certificates to exact approved revisions and digests' );
jotform_check( false !== strpos( $pdf, 'while ( ! empty( $certificate_indexes[ $course_key ] ) )' ), 'all uploaded certificates are appended after their lesson information page' );
jotform_check( false !== strpos( $repository, "'training_superseded'" ) && false !== strpos( $repository, "'revoked'" ), 'supersession and revocation are persisted as distinct workflow states' );
jotform_check( false !== strpos( $repository, 'reject_pending_training' ) && false !== strpos( $repository, "'status' => 'pending', 'approved_revision_id' => null" ) && false === strpos( $repository, 'set_training_status' ), 'rejection is an explicit pending-only repository transition' );
jotform_check( false !== strpos( $ui, 'ghca_acd_jotform_ownership_claim' ) && false !== strpos( $ui, 'ownership_claim_qid' ), 'the plugin exposes an authenticated claim bridge and administrator mapping for the hidden claim field' );
jotform_check( false !== strpos( $repository, 'employee_user_id=IF(employee_user_id=0,VALUES(employee_user_id),employee_user_id)' ), 'idempotent source re-indexing cannot erase a reviewer-confirmed document owner' );
jotform_check( false !== strpos( $repository, "array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )" ), 'training candidate insert has one format token for each of its thirteen persisted values' );
jotform_check( false !== strpos( $repository, 'if ( false === $wpdb->query( $sql ) )' ) && false !== strpos( $repository, 'catch ( Throwable $e ) {' ) && false !== strpos( $repository, 'return 1 === (int) $inserted ? $request_id : false;' ), 'document upsert and append-only event writes expose false and thrown persistence failures to their callers' );
jotform_check( false !== strpos( $repository, 'ORDER BY id FOR UPDATE' ) && false !== strpos( $repository, '$locked_rows as $locked_row' ) && false !== strpos( $repository, "'status' => 'superseded', 'updated_at' => \$now" ) && false === strpos( $repository, "UPDATE {\$training_table} SET status='superseded'" ), 'approval locks every revision before deriving the authoritative supersession/event set' );
jotform_check( false !== strpos( $repository, '$snapshot_date !== $date' ) && false !== strpos( $ui, "'completion_date' => \$date" ), 'approved immutable snapshot completion date must equal the validated reviewer date' );
$stream_document_body = jotform_method_body( $ui, 'public static function stream_document(): void' );
jotform_check( false !== strpos( $repository, 'private static function rollback(): void' ) && false !== strpos( $repository, 'catch ( Throwable $e ) { return null; }' ) && '' !== $stream_document_body && false !== strpos( $stream_document_body, "if ( ! GHCA_ACD_Jotform_Repository::event( 'document_' . \$grant['access']" ) && strpos( $stream_document_body, "header( 'Content-Length: '" ) > strpos( $stream_document_body, "if ( ! GHCA_ACD_Jotform_Repository::event( 'document_' . \$grant['access']" ), 'rollback and grant database throwables fail closed and stream event persistence precedes response headers' );
jotform_check( substr_count( $repository, "false === \$wpdb->query( 'START TRANSACTION' )" ) >= 4 && substr_count( $repository, "false === \$wpdb->query( 'COMMIT' )" ) >= 4, 'all multi-write workflow transitions fail closed when transaction setup or commit fails' );
jotform_check( false !== strpos( $ui, 'formnovalidate>PDF preflight' ) && false !== strpos( $ui, 'formnovalidate>Reject' ) && false !== strpos( $ui, 'Configured evidence reference is invalid or unavailable' ), 'non-approval actions bypass only browser approval validation and invalid local evidence metadata has no submission-ID fallback' );

jotform_check( false !== strpos( $ui, 'ghca_acd_jotform_manual_token' ) && false !== strpos( $ui, 'ghca_acd_jotform_manual_access' ) && false !== strpos( $ui, 'issue_document_grant' ) && false !== strpos( $ui, 'consume_document_grant' ), 'manual certificates are opened through short-lived single-use grants' );
jotform_check( false !== strpos( $ui, 'function stream_manual_evidence' ) && substr_count( $ui, 'accessible_manual_evidence' ) >= 3, 'the stream re-authorizes the consumed grant instead of trusting it' );
jotform_check( false !== strpos( $ui, 'read_stored_evidence' ) && false === strpos( $ui, 'file_put_contents' ), 'streamed evidence is decrypted in memory and never written to disk' );
$stream_manual_body = jotform_method_body( $ui, 'public static function stream_manual_evidence(): void' );
jotform_check( '' !== $stream_manual_body && false !== strpos( $stream_manual_body, "if ( ! GHCA_ACD_Jotform_Repository::event( 'manual_evidence_' . \$grant['access']" ) && strpos( $stream_manual_body, "header( 'Content-Type: application/pdf' )" ) > strpos( $stream_manual_body, "if ( ! GHCA_ACD_Jotform_Repository::event( 'manual_evidence_' . \$grant['access']" ) && strpos( $stream_manual_body, 'echo $bytes' ) > strpos( $stream_manual_body, "header( 'Content-Type: application/pdf' )" ), 'the access event is persisted before any certificate byte is sent' );
jotform_check( false !== strpos( $ui, 'manual_evidence_manifest' ) && false !== strpos( $ui, 'external_training_summary_label' ) && false === strpos( $ui, "ucfirst( (string) \$training[0]['status'] )" ), 'the drawer lists every manual entry and summarizes all states rather than the newest row' );
jotform_check( false !== strpos( $repository, 'function manual_training_for_employee' ) && false !== strpos( $repository, 'function external_training_summary' ), 'the repository exposes full manual history and state counts to the drawer' );

jotform_check( false !== strpos( $ui, 'function employee_record_rows' ) && false !== strpos( $ui, "'kind'       => 'jotform'" ) && false !== strpos( $ui, "'kind'       => 'manual'" ), 'the drawer record list merges indexed documents and manual entries' );
jotform_check( false !== strpos( $repository, 'function employee_record_count' ) && false !== strpos( $repository, 'self::document_count( $user_id ) + self::manual_entry_count( $user_id )' ), 'the record counter includes manual entries' );
jotform_check( false !== strpos( $ui, 'self::employee_record_rows( $employee_id, $search )' ) && false === strpos( $ui, "search_documents_for_employee( \$employee_id, \$search, \$per_page" ), 'the View All list is paged over the same merged set as the counter' );
jotform_check( false === strpos( $repository, "form_id=%s" ) || false !== strpos( $repository, 'MANUAL_FORM_ID' ), 'manual rows stay out of the Jotform documents table and are merged only for display' );

$ajax_handlers = file_get_contents( $root . '/includes/class-ajax-handlers.php' );
$dashboard_css = file_get_contents( $root . '/assets/dashboard.css' );
jotform_check( false !== strpos( $ui, 'ghca-acd__jotform-foot' ) && false !== strpos( $dashboard_css, '.ghca-acd__drawer--employee .ghca-acd__jotform-foot' ) && false !== strpos( $dashboard_css, 'justify-content: space-between' ), 'the records roll-up and View All share one aligned card footer' );
jotform_check( false !== strpos( $dashboard_css, 'flex-wrap: nowrap; white-space: nowrap' ) && false !== strpos( $dashboard_css, '.ghca-acd__jotform-indexed i { flex: 0 0 auto' ), 'the status dot cannot wrap above or shrink away from its label' );
jotform_check( false !== strpos( $ajax_handlers, 'GHCA_ACD_Jotform_Repository::employee_record_count( $user_id )' ) && false === strpos( $ajax_handlers, 'GHCA_ACD_Jotform_Repository::document_count( $user_id )' ), 'the Documents tab counter counts the same merged set as the section counter' );
jotform_check( 1 === substr_count( $ui, 'class="ghca-acd__jotform-actions"' ) && false !== strpos( $dashboard_css, '.ghca-acd__jotform-actions' ), 'record actions share one grid cell, so a second control cannot wrap onto its own line' );
jotform_check( false !== strpos( $ui, "'status_key' => \$status" ) && false !== strpos( $dashboard_css, 'ghca-acd__jotform-indexed--approved' ) && false !== strpos( $dashboard_css, 'ghca-acd__jotform-indexed--rejected' ), 'review status is colour-coded, not only worded' );

$dashboard_js = file_get_contents( $root . '/assets/dashboard.js' );
$jotform_js   = file_get_contents( $root . '/assets/jotform.js' );
jotform_check( false !== strpos( $dashboard_js, 'window.ghcaAcdOpenCertificateModal' ) && false !== strpos( $dashboard_js, 'ghcaAcdOpenCertificateModal(urls[0]' ), 'manual certificates preview inside the shared certificate modal' );
jotform_check( false !== strpos( $dashboard_js, 'currentDownloadUrl' ) && false !== strpos( $dashboard_js, 'var sourceUrl = currentDownloadUrl || currentUrl' ), 'the modal download button uses its own grant, because the preview spends one' );
jotform_check( false !== strpos( $jotform_js, 'ghcaAcdOpenCertificateModal' ) && false !== strpos( $jotform_js, "item.kind === 'manual' ? 'data-ghca-manual-open'" ), 'indexed documents also preview in the modal and each row uses its own open action' );

jotform_check( 'fatal five constipation' === GHCA_ACD_Jotform_Repository::normalize_title( 'Fatal Five: Constipation' ), 'legacy title aliases normalize deterministically' );
jotform_check( '2026-08-26' === GHCA_ACD_Jotform_Repository::sanitize_date( '08-26-2026' ), 'completion dates normalize to an audit-safe ISO date' );
jotform_check( null === GHCA_ACD_Jotform_Repository::sanitize_date( 'not-a-date' ), 'malformed completion dates fail closed' );

$menu      = file_get_contents( $root . '/includes/class-admin-menu.php' );
$settings  = file_get_contents( $root . '/includes/class-settings.php' );
$mapping   = file_get_contents( $root . '/includes/class-audit-mapping.php' );
$oltl_ui   = file_get_contents( $root . '/includes/oltl/class-oltl-ui.php' );
$messaging = file_get_contents( $root . '/includes/messaging/class-messaging-ui.php' );
$bootstrap = file_get_contents( $root . '/gridhouse-admin-compliance-dashboard.php' );
$admin_sources = $settings . $mapping . $ui . $oltl_ui . $messaging;

jotform_check( false !== strpos( $menu, "add_menu_page(" ) && 1 === substr_count( $menu, "add_menu_page(" ) && false === strpos( $admin_sources, 'add_options_page' ) && false === strpos( $admin_sources, 'add_management_page' ), 'every screen registers under one top-level menu instead of Settings and Tools' );

$expected_pages = array( 'ghca-acd-settings', 'ghca-acd-permissions', 'ghca-acd-messaging', 'ghca-acd-audit-mapping', 'ghca-acd-jotform', 'ghca-acd-external-training', 'ghca-acd-external-catalog', 'ghca-acd-oltl-training' );
$registered = true;
foreach ( $expected_pages as $slug ) {
	if ( false === strpos( $admin_sources, "'" . $slug . "'" ) || false === strpos( $menu, "'" . $slug . "'" ) ) { $registered = false; }
}
jotform_check( $registered && 8 === count( $expected_pages ), 'all eight settings screens are sub-pages of the shared menu and are listed for the legacy redirect' );

jotform_check( false === strpos( $admin_sources, "admin_url( 'options-general.php' )" ) && false === strpos( $admin_sources, "admin_url( 'tools.php' )" ) && false !== strpos( $ui, 'GHCA_ACD_Admin_Menu::url(' ) && false !== strpos( $messaging, 'GHCA_ACD_Admin_Menu::url(' ), 'admin URLs are built through the menu helper, not a hard-coded parent file' );

jotform_check( false !== strpos( $menu, "add_action( 'init', array( __CLASS__, 'redirect_legacy_url' ) )" ) && false === strpos( $menu, "add_action( 'admin_init', array( __CLASS__, 'redirect_legacy_url' ) )" ), 'the legacy-bookmark redirect runs before wp-admin/menu.php can refuse the old parent' );

jotform_check( false !== strpos( $menu, 'user_can_manage_messaging_settings' ) && false !== strpos( $menu, "return 'read';" ), 'the parent menu is not more restrictive than its most permissive child' );

jotform_check( false !== strpos( $menu, "remove_submenu_page( self::SLUG, self::SLUG )" ), 'the duplicate parent sub-page is removed so the menu opens the first permitted screen' );

jotform_check( false !== strpos( $bootstrap, 'GHCA_ACD_Admin_Menu::init();' ) && strpos( $bootstrap, 'GHCA_ACD_Admin_Menu::init();' ) < strpos( $bootstrap, 'GHCA_ACD_Settings::init();' ), 'the menu controller boots before the screens that attach to it' );

echo 0 === $fails ? "\nALL PASS\n" : "\n{$fails} FAILED\n";
exit( 0 === $fails ? 0 : 1 );
