<?php
/** Focused source-boundary checks for the Clinical Precision employee drawer. */

$root          = dirname( __DIR__ );
$ajax          = (string) file_get_contents( $root . '/includes/class-ajax-handlers.php' );
$messaging     = (string) file_get_contents( $root . '/includes/messaging/class-messaging-ui.php' );
$jotform       = (string) file_get_contents( $root . '/includes/jotform/class-jotform-ui.php' );
$dashboard_js  = (string) file_get_contents( $root . '/assets/dashboard.js' );
$dashboard_css = (string) file_get_contents( $root . '/assets/dashboard.css' );
$plugin        = (string) file_get_contents( $root . '/gridhouse-admin-compliance-dashboard.php' );
$fails         = 0;

function employee_drawer_v3_check( bool $condition, string $message ): void {
	global $fails;
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
	$fails += $condition ? 0 : 1;
}

preg_match( '/\* Version: ([^\r\n]+)/', $plugin, $header_version );
preg_match( "/const VERSION\s*=\s*'([^']+)'/", $plugin, $const_version );
employee_drawer_v3_check( ! empty( $header_version[1] ) && $header_version[1] === ( $const_version[1] ?? '' ), 'plugin header and runtime version match' );
employee_drawer_v3_check( false !== strpos( $ajax, 'ghca-acd__drawer-compliance-ring' ) && false !== strpos( $ajax, 'ghca-acd__drawer-attention-actions' ) && false !== strpos( $ajax, 'ghca-acd__drawer-course-progress-inline' ), 'Training uses the Clinical Precision summary, attention card, and compact records' );
employee_drawer_v3_check( false !== strpos( $ajax, 'ghca-acd__drawer-admin-hero' ) && false !== strpos( $ajax, 'ghca-acd__drawer-employment-card' ) && false !== strpos( $ajax, 'Employee is currently in good standing and authorized for active service.' ) && false !== strpos( $ajax, 'ghca-acd__drawer-system-meta' ), 'Administration uses the approved overview, standing statement, employment card, and real System Meta' );
employee_drawer_v3_check( false !== strpos( $jotform, 'ghca-acd__jotform-drawer-head' ) && false !== strpos( $jotform, 'ghca-acd__jotform-file-copy' ) && false !== strpos( $jotform, 'data-ghca-documents-all' ), 'Documents uses the indexed-record list while preserving the existing all-documents action' );
employee_drawer_v3_check( false !== strpos( $messaging, 'ghca-acd__history-section-head' ) && false !== strpos( $messaging, 'ghca-acd__history-card-meta' ) && false !== strpos( $messaging, '<time' ) && false !== strpos( $messaging, 'ghca-acd__history-card-excerpt' ) && false !== strpos( $messaging, 'data-ghca-history-open' ), 'Communications places channel and date/time above each message and preserves full-history access' );
employee_drawer_v3_check( false !== strpos( $ajax, 'user_can_view_employee_documents()' ) && false !== strpos( $ajax, 'user_can_view_communication_history()' ) && false !== strpos( $ajax, 'user_can_edit_records()' ), 'tab visibility remains permission gated' );
employee_drawer_v3_check( false !== strpos( $ajax, 'data-ghca-reminder=' ) && false !== strpos( $ajax, 'data-ghca-pdf-packet=' ) && false !== strpos( $ajax, 'data-ghca-edit-records=' ) && false !== strpos( $ajax, 'data-ghca-mark-reviewed=' ), 'existing reminder, packet, edit, and review hooks are preserved' );
employee_drawer_v3_check( false === strpos( $ajax, 'Employee #%d' ) && false === strpos( $ajax, 'Record Hash' ) && false === strpos( $ajax, 'Request new document' ), 'employee IDs and prototype-only records/actions are not introduced' );
employee_drawer_v3_check( false !== strpos( $dashboard_css, '1.7.0 Clinical Precision employee drawer' ) && false !== strpos( $dashboard_css, 'width: min(850px, 100vw)' ) && false !== strpos( $dashboard_css, 'overflow-y: auto' ), 'the final scoped style layer owns drawer width and the tab-content scroll region' );
employee_drawer_v3_check( false !== strpos( $dashboard_css, 'var(--ghca-ui-brand)' ) && false !== strpos( $dashboard_css, 'var(--ghca-ui-secondary)' ), 'site-configured brand colors remain the action and accent sources' );
employee_drawer_v3_check( false !== strpos( $dashboard_css, '1.7.0 corrected Stitch parity' ) && false !== strpos( $dashboard_css, 'font-family: var(--ent-font-sans' ) && false !== strpos( $dashboard_css, '.ghca-acd__drawer-tabs [role="tab"]:focus-visible' ) && false !== strpos( $dashboard_css, 'flex-direction: row;' ), 'the parity layer restores dashboard typography, underline-only tab focus, and the handoff footer direction' );
employee_drawer_v3_check( strpos( $ajax, '<div class="ghca-acd__drawer-more-menu">' ) < strpos( $ajax, 'Compliance Report' ) && strpos( $ajax, 'Compliance Report' ) < strpos( $ajax, 'Orientation Packet' ) && false !== strpos( $ajax, 'ghca-acd__drawer-action--primary' ), 'Compliance Report lives in More while More and Send Reminder remain the two visible footer actions' );
employee_drawer_v3_check( false !== strpos( $dashboard_js, 'selectedTabs[String(userId)]' ) && false !== strpos( $dashboard_js, "e.key === 'ArrowRight'" ) && false !== strpos( $dashboard_js, "e.key === 'Home'" ) && false !== strpos( $dashboard_js, "e.key === 'End'" ), 'per-employee tab memory and keyboard navigation remain intact' );
employee_drawer_v3_check( false !== strpos( $dashboard_js, 'closeDrawerMoreMenus' ) && false !== strpos( $dashboard_js, "document.querySelectorAll('.ghca-acd__drawer-more[open]')" ), 'the More menu still closes through the shared outside-click behavior' );
employee_drawer_v3_check( false !== strpos( $dashboard_css, '.ghca-acd__overlay--reminder' ) && false !== strpos( $dashboard_css, '.ghca-acd__overlay--history' ) && false !== strpos( $dashboard_css, '.ghca-acd__overlay--documents' ) && false !== strpos( $dashboard_css, '.ghca-acd__overlay--packet' ), 'connected popup shells remain present and unchanged by the drawer scope' );

exit( $fails ? 1 : 0 );
