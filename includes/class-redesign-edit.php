<?php
/**
 * Board markup for the Edit Records sub-page of the redesigned employee drawer: the form, the packet generator
 * and the agency-training card.
 *
 * Presentation only. The form posts the same field names to the same save handler, and every control the
 * existing scripts bind to (data-ghca-edit-form, data-ghca-pdf-packet, data-ghca-audit-*, data-ghca-agency-*,
 * data-ghca-packet-*) keeps its attribute.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-training-year.php';

final class GHCA_ACD_Redesign_Edit {
	private static function icon( string $name, string $size = '' ): string {
		return GHCA_ODP_UI::icon( $name, $size );
	}

	/**
	 * One labelled form field.
	 *
	 * @param string $control Already-escaped control markup.
	 */
	private static function field( string $id, string $label, string $control, string $help = '', bool $wide = false ): string {
		return '<div class="gh-field' . ( $wide ? ' gh-dr-wide' : '' ) . '"><label class="gh-label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>' . $control . ( '' !== $help ? '<span class="gh-help">' . esc_html( $help ) . '</span>' : '' ) . '</div>';
	}

	/**
	 * The form, as the response to ghca_acd_get_edit_records_form.
	 *
	 * @param array<string,mixed> $m Result of GHCA_ACD_AJAX::edit_records_model().
	 */
	public static function render( array $m ): string {
		$uid      = (int) $m['user_id'];
		$suspend  = (bool) $m['edit_suspended'];
		$sel_type = (string) $m['employment_type'];
		$record   = (array) $m['employment_record'];

		$h = '<form class="gh-dr-edit" data-ghca-edit-form data-user-id="' . esc_attr( (string) $uid ) . '">';

		// 1. Employment and role. Role, group and status are derived, so they are read-only and unnamed: nothing is submitted for them.
		$h .= '<section class="gh-card"><div class="gh-card-hd"><h3 class="gh-h4">' . esc_html__( '1 · Employment & role', 'ghca-acd' ) . '</h3></div><div class="gh-card-bd gh-dr-grid2">';
		$opts = '<option value="">' . esc_html__( 'Not set', 'ghca-acd' ) . '</option>';
		foreach ( (array) $m['employment_options'] as $value => $label ) {
			$opts .= '<option value="' . esc_attr( (string) $value ) . '"' . selected( $sel_type, (string) $value, false ) . '>' . esc_html( (string) $label ) . '</option>';
		}
		$h .= self::field( 'ghca-edit-employment-type', __( 'Employment type', 'ghca-acd' ), '<select class="gh-select" id="ghca-edit-employment-type" name="employment_type">' . $opts . '</select>' );
		// Annual hours requirement (24 or 12). Settable before the training-year rules are enabled, so the dry run can use it.
		$req  = '<option value="">' . esc_html__( 'Not set — 24 h assumed', 'ghca-acd' ) . '</option>';
		foreach ( GHCA_ACD_Training_Year::requirements() as $value => $choice ) {
			$req .= '<option value="' . esc_attr( (string) $value ) . '"' . selected( (string) ( $m['training_requirement'] ?? '' ), (string) $value, false ) . '>' . esc_html( (string) $choice[0] ) . '</option>';
		}
		$h .= self::field( 'ghca-edit-training-requirement', __( 'Annual training requirement (§ 6100.143)', 'ghca-acd' ), '<select class="gh-select" id="ghca-edit-training-requirement" name="training_requirement">' . $req . '</select>', __( 'Sets the annual hours this employee must complete: 24 or 12.', 'ghca-acd' ) );
		$h .= self::field( 'ghca-edit-role', __( 'Primary role', 'ghca-acd' ), '<input id="ghca-edit-role" class="gh-input is-readonly" value="' . esc_attr( '' !== (string) $m['edit_role_label'] ? (string) $m['edit_role_label'] : '—' ) . '" readonly />' );
		$h .= self::field( 'ghca-edit-group', __( 'Group', 'ghca-acd' ), '<input id="ghca-edit-group" class="gh-input is-readonly" value="' . esc_attr( '' !== (string) $m['edit_group'] ? (string) $m['edit_group'] : '—' ) . '" readonly />' );
		$h .= '<div class="gh-field"><span class="gh-label">' . esc_html__( 'Employment status', 'ghca-acd' ) . '</span><span class="gh-st gh-st--lg gh-st--' . ( $suspend ? 'suspended' : 'active' ) . ' gh-dr-chip">' . esc_html( (string) $m['edit_status'] ) . '</span></div>';
		$h .= '<span class="gh-help gh-dr-wide">' . esc_html__( 'Role and group come from the user account and group assignment. Employment status follows the account’s active state.', 'ghca-acd' ) . '</span></div></section>';

		// 2. Milestones and dates.
		$h .= '<section class="gh-card"><div class="gh-card-hd"><h3 class="gh-h4">' . esc_html__( '2 · Milestones & dates', 'ghca-acd' ) . '</h3></div><div class="gh-card-bd gh-dr-grid2">';
		$h .= self::field( 'ghca-edit-registration', __( 'Account registration date', 'ghca-acd' ), '<input id="ghca-edit-registration" class="gh-input" type="datetime-local" name="registration_date" value="' . esc_attr( (string) $m['registered_local'] ) . '" />', __( 'Account creation only; not the verified employment start date.', 'ghca-acd' ) );
		$h .= self::field( 'ghca-edit-employment-date', __( 'Verified employment start date', 'ghca-acd' ), '<input id="ghca-edit-employment-date" class="gh-input" type="date" name="employment_date" value="' . esc_attr( (string) ( $record['date'] ?? '' ) ) . '" />' );
		$h .= self::field( 'ghca-edit-employment-source', __( 'Employment-date source reference', 'ghca-acd' ), '<input id="ghca-edit-employment-source" class="gh-input" type="text" name="employment_source" maxlength="500" value="' . esc_attr( (string) ( $record['source'] ?? '' ) ) . '" />', __( 'Reference the reviewed HR record; do not paste private document contents.', 'ghca-acd' ), true );
		$h .= '<div class="gh-field gh-dr-wide"><label class="gh-check gh-dr-verify" for="ghca-edit-employment-verified"><input id="ghca-edit-employment-verified" type="checkbox" name="employment_verified" value="1" /> <span>' . esc_html__( 'I checked this employment date against its source.', 'ghca-acd' ) . '</span></label><span class="gh-help">' . esc_html__( 'Required for a new or changed date/source. The verifier and time are recorded automatically. Blank fields do not erase a verified date.', 'ghca-acd' ) . '</span></div>';
		$h .= self::field( 'ghca-edit-first-service', __( 'First service date', 'ghca-acd' ), '<input id="ghca-edit-first-service" class="gh-input" type="date" name="first_service_date" value="' . esc_attr( (string) $m['edit_first_val'] ) . '" />', __( 'Also editable from the Audit Data tab; used in the audit packet.', 'ghca-acd' ) );
		$h .= self::field( 'ghca-edit-worked-alone', __( 'Worked alone authorized', 'ghca-acd' ), '<input id="ghca-edit-worked-alone" class="gh-input" type="date" name="worked_alone_date" value="' . esc_attr( (string) $m['edit_alone_val'] ) . '" />', __( 'Must fall on or after orientation completion, or the audit records a violation.', 'ghca-acd' ) );
		$h .= '<div class="gh-field"><span class="gh-label">' . esc_html__( 'Orientation completion', 'ghca-acd' ) . '</span><span class="gh-st gh-st--lg gh-st--progress gh-dr-chip">' . esc_html( '' !== (string) $m['edit_orient'] ? (string) $m['edit_orient'] : '—' ) . '</span><span class="gh-help">' . esc_html__( 'Derived from the orientation course records below.', 'ghca-acd' ) . '</span></div></div></section>';

		// 3. Course records. Each course keeps its legacy field names.
		$h .= '<section class="gh-card"><div class="gh-card-hd"><h3 class="gh-h4">' . esc_html__( '3 · Course records', 'ghca-acd' ) . '</h3><span class="gh-badge gh-badge--outline">' . self::icon( 'refresh', 'sm' ) . esc_html__( 'Syncs to LearnDash', 'ghca-acd' ) . '</span></div><div class="gh-card-bd gh-dr-courses">';
		if ( empty( $m['edit_courses'] ) ) {
			$h .= '<p class="gh-small gh-muted">' . esc_html__( 'No assigned courses.', 'ghca-acd' ) . '</p>';
		} else {
			foreach ( $m['edit_courses'] as $c ) {
				$cid   = (int) $c['id'];
				$done  = (bool) $c['completed'];
				$h    .= '<div class="gh-dr-course" data-course-id="' . esc_attr( (string) $cid ) . '"><div class="gh-dr-course-hd"><strong>' . esc_html( (string) $c['title'] ) . '</strong><span class="gh-st gh-st--' . ( $done ? 'completed' : 'notstarted' ) . '">' . esc_html( $done ? __( 'Completed', 'ghca-acd' ) : __( 'Incomplete', 'ghca-acd' ) ) . '</span></div><div class="gh-dr-course-grid">';
				$h    .= '<label class="gh-check" for="ghca-edit-c' . $cid . '-done"><input id="ghca-edit-c' . $cid . '-done" type="checkbox" name="course[' . $cid . '][mark_complete]" value="1"' . checked( $done, true, false ) . ' /> ' . esc_html__( 'Mark this course complete', 'ghca-acd' ) . '</label>';
				$h    .= '<div class="gh-field"><label class="gh-label gh-label--sm" for="ghca-edit-c' . $cid . '-date">' . esc_html__( 'Completion date', 'ghca-acd' ) . '</label><input id="ghca-edit-c' . $cid . '-date" class="gh-input" type="datetime-local" name="course[' . $cid . '][completed]" value="' . esc_attr( (string) $c['completed_local'] ) . '" /></div>';
				$h    .= '<div class="gh-field"><label class="gh-label gh-label--sm" for="ghca-edit-c' . $cid . '-min">' . esc_html__( 'Time spent (min)', 'ghca-acd' ) . '</label><input id="ghca-edit-c' . $cid . '-min" class="gh-input gh-num" type="number" min="0" step="1" inputmode="numeric" name="course[' . $cid . '][minutes]" value="' . esc_attr( (string) $c['minutes'] ) . '" /></div></div></div>';
			}
			$h .= '<span class="gh-help">' . esc_html__( 'Uncheck and save to mark incomplete. If all lessons are complete, the last lesson is reopened. Previous completion data is retained in correction history; external training is unchanged.', 'ghca-acd' ) . '</span>';
		}
		$h .= '</div></section>';

		// The audit statement, worded as the legacy form words it: no claim beyond what the plugin does.
		$h .= '<div class="gh-alert gh-alert--neutral">' . self::icon( 'shield' ) . '<div class="gh-alert-body"><span class="gh-alert-t">' . esc_html__( 'Audit logging', 'ghca-acd' ) . '</span><p>' . sprintf(
			/* translators: %s: the acting administrator's username. */
			esc_html__( 'Record changes are written to the audit log against your account (%s). Packet evidence is verified with SHA-256 when it is stored and again when it is compiled.', 'ghca-acd' ),
			'<code>' . esc_html( '' !== (string) $m['actor_login'] ? (string) $m['actor_login'] : __( 'unknown', 'ghca-acd' ) ) . '</code>'
		) . '</p></div></div>';

		$h .= '<div class="gh-dr-editfoot"><span class="gh-small gh-muted" data-ghca-edit-dirty role="status" aria-live="polite">' . esc_html__( 'No unsaved changes', 'ghca-acd' ) . '</span><div class="gh-actions"><button type="button" class="gh-btn gh-btn--secondary" data-ghca-edit-close>' . esc_html__( 'Cancel', 'ghca-acd' ) . '</button><button type="submit" class="gh-btn gh-btn--primary" data-ghca-edit-save>' . esc_html__( 'Save changes', 'ghca-acd' ) . '</button></div></div>';
		return $h . '</form>';
	}

	/**
	 * The sub-page itself: back bar, heading, the slot the form loads into, the agency-training card and the
	 * packet generator. Lives inside the Administration panel, hidden until Edit records is chosen.
	 *
	 * @param array<string,mixed> $m Drawer model.
	 */
	public static function subpage_html( array $m ): string {
		$uid  = (int) $m['user_id'];
		$name = (string) $m['employee_name'];

		$h  = '<nav class="gh-sub-bar" aria-label="' . esc_attr__( 'Sub-page navigation', 'ghca-acd' ) . '"><button type="button" class="gh-btn gh-btn--ghost gh-btn--sm gh-btn--lnk" data-ghca-subpage-back>' . self::icon( 'arrow-left', 'sm' ) . esc_html__( 'Administration', 'ghca-acd' ) . '</button>';
		$h .= '<span class="gh-small gh-muted">' . esc_html( trim( $name . ( '' !== (string) $m['group_label'] ? ' · ' . (string) $m['group_label'] : '' ) ) ) . '</span></nav>';
		$h .= '<div class="gh-sub-head"><h3 class="gh-h3" data-ghca-subpage-head tabindex="-1">' . esc_html__( 'Edit records & generate packets', 'ghca-acd' ) . '</h3><p class="gh-p gh-secondary gh-small">' . esc_html( sprintf( /* translators: %s: employee name */ __( 'Update foundational dates, course records, and compile the archival packet for %s.', 'ghca-acd' ), $name ) ) . '</p></div>';
		$h .= '<div class="gh-sub-grid"><div class="gh-sub-main"><div id="ghca-acd-edit-subpage-body" aria-busy="false"><p class="gh-small gh-muted">' . esc_html__( 'Loading records…', 'ghca-acd' ) . '</p></div>';

		$h .= '<section class="gh-card gh-dr-agency" data-ghca-agency="' . esc_attr( (string) $uid ) . '" aria-label="' . esc_attr__( 'Agency-delivered training', 'ghca-acd' ) . '"><div class="gh-card-hd"><div><h3 class="gh-h4">' . esc_html__( 'Agency-delivered training', 'ghca-acd' ) . '</h3><span class="gh-small gh-muted">' . esc_html__( 'Confirm in-person training for the Annual packet period selected in Packet Generator. Save separately from course records. Orientation is unchanged.', 'ghca-acd' ) . '</span></div></div>';
		$h .= '<div class="gh-card-bd gh-dr-form"><div><button type="button" class="gh-btn gh-btn--secondary gh-btn--sm gh-btn--lnk" data-ghca-agency-reload>' . esc_html__( 'Load / refresh saved confirmations', 'ghca-acd' ) . '</button></div><p class="gh-small gh-muted gh-dr-status" data-ghca-agency-status role="status" aria-live="polite"></p><div data-ghca-agency-body></div></div></section></div>';

		$h .= '<aside class="gh-sub-side">' . self::packet_html( $m ) . '</aside></div>';
		return $h;
	}

	/** @param array<string,mixed> $m */
	private static function packet_html( array $m ): string {
		$uid = (int) $m['user_id'];
		$h   = '<section class="gh-card gh-pk" aria-labelledby="ghca-acd-packet-title"><div class="gh-card-hd gh-pk-hd"><div><span class="gh-eyebrow">' . esc_html__( 'Packet generator', 'ghca-acd' ) . '</span><h3 class="gh-h4" id="ghca-acd-packet-title">' . esc_html__( 'Compliance packet', 'ghca-acd' ) . '</h3><span class="gh-small gh-muted">' . esc_html__( 'Compiles the compliance summary and stored certificates into a single archival PDF.', 'ghca-acd' ) . '</span></div>' . self::icon( 'archive', 'lg' ) . '</div><div class="gh-card-bd gh-dr-form">';
		$h  .= '<fieldset class="gh-dr-period" data-ghca-audit-period><legend class="gh-label">' . esc_html__( 'Annual packet period', 'ghca-acd' ) . '</legend>';
		$h  .= '<div class="gh-field"><label class="gh-label gh-label--sm" for="ghca-pk-mode">' . esc_html__( 'Period to report', 'ghca-acd' ) . '</label><select id="ghca-pk-mode" class="gh-select" data-ghca-audit-mode><option value="cycle">' . esc_html__( 'Current annual training cycle', 'ghca-acd' ) . '</option><option value="custom">' . esc_html__( 'Choose audit reporting dates', 'ghca-acd' ) . '</option></select></div>';
		$h  .= '<div data-ghca-audit-dates hidden><div class="gh-field"><label class="gh-label gh-label--sm" for="ghca-pk-start">' . esc_html__( 'Audit start date', 'ghca-acd' ) . '</label><input id="ghca-pk-start" class="gh-input" type="date" data-ghca-audit-start required disabled></div><div class="gh-field"><label class="gh-label gh-label--sm" for="ghca-pk-end">' . esc_html__( 'Audit end date (inclusive)', 'ghca-acd' ) . '</label><input id="ghca-pk-end" class="gh-input" type="date" data-ghca-audit-end required disabled></div>';
		$h  .= '<span class="gh-help">' . esc_html__( 'Reports evidence within these dates. Does not change the saved annual cycle or assess annual compliance. Applies to the Annual button only.', 'ghca-acd' ) . '</span></div></fieldset>';

		$h .= '<div class="gh-pk-run">';
		if ( $m['can_documents'] ) {
			$h .= '<button type="button" class="gh-btn gh-btn--secondary gh-btn--block" data-ghca-pdf-packet="' . esc_attr( (string) $uid ) . '" data-tracker="orientation">' . self::icon( 'archive' ) . esc_html__( 'Orientation', 'ghca-acd' ) . '</button>';
			$h .= '<button type="button" class="gh-btn gh-btn--primary gh-btn--block" data-ghca-pdf-packet="' . esc_attr( (string) $uid ) . '" data-tracker="annual">' . self::icon( 'archive' ) . esc_html__( 'Annual', 'ghca-acd' ) . '</button>';
			if ( '' !== (string) $m['oltl_html'] ) {
				$h .= '<button type="button" class="gh-btn gh-btn--secondary gh-btn--block" data-ghca-pdf-packet="' . esc_attr( (string) $uid ) . '" data-tracker="oltl_training">' . self::icon( 'archive' ) . esc_html__( 'OLTL training', 'ghca-acd' ) . '</button>';
			}
		} else {
			$h .= '<p class="gh-small gh-muted">' . esc_html__( 'Packet access requires View Employee Documents permission.', 'ghca-acd' ) . '</p>';
		}
		$h .= '</div>';

		// Packet-ready notice for packets started from this card (Drawer-EditRecords board, below the generator).
		$h .= '<div class="gh-pk-ready" data-ghca-packet-slot></div>';

		// Mirrors the live job; idle until a packet run starts. The script sets each step's data-state.
		$h .= '<div class="gh-pk-live" data-ghca-packet-mirror data-ghca-board hidden><div class="gh-pk-metric"><span class="gh-label">' . esc_html__( 'Compilation status', 'ghca-acd' ) . '</span><span class="gh-pk-pct"><strong data-ghca-packet-pct>0</strong>%</span></div>';
		$h .= '<div class="gh-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" aria-label="' . esc_attr__( 'Packet compilation progress', 'ghca-acd' ) . '" data-ghca-packet-track><span data-ghca-packet-bar></span></div><p class="gh-small gh-muted" data-ghca-packet-label aria-live="polite"></p><ol class="gh-pk-steps">';
		foreach ( array(
			'summary'      => array( __( 'Compliance summary', 'ghca-acd' ), __( 'Builds the training and competency summary page.', 'ghca-acd' ) ),
			'certificates' => array( __( 'Certificate retrieval', 'ghca-acd' ), __( 'Collects stored certificates and approved external evidence.', 'ghca-acd' ) ),
			'merge'        => array( __( 'Merge & verify', 'ghca-acd' ), __( 'Merges the PDF and re-checks evidence integrity before download.', 'ghca-acd' ) ),
		) as $key => $copy ) {
			$h .= '<li class="gh-pk-step" data-ghca-packet-step="' . esc_attr( $key ) . '"><span class="gh-pk-dot" aria-hidden="true"></span><div><strong>' . esc_html( $copy[0] ) . '</strong><span class="gh-small gh-muted">' . esc_html( $copy[1] ) . '</span></div></li>';
		}
		$h .= '</ol></div>';

		// Accurate to GHCA_ACD_Audit_Pdf_Jobs: files live in a private directory and are cleared after about an hour.
		return $h . '<span class="gh-help">' . esc_html__( 'Generated packets are held in private storage and cleared automatically about an hour after they are built.', 'ghca-acd' ) . '</span></div></section>';
	}
}
