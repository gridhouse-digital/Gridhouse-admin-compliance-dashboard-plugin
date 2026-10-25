<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** References to agency-held ISP training evidence, not a document store or hour award. */
final class GHCA_ISP_Evidence_Review {
	public static function fields(): array {
		return array(
			'evidence_reference' => 'Secure evidence record ID (no names or URLs)',
			'scope_reference' => 'Assigned-plan register ID/version (identifies all relevant individuals and plan versions in your secure records)',
			'training_date' => 'Training completion date',
			'duration_minutes' => 'Documented training duration (minutes; not automatically credited)',
			'training_source' => 'Training source / trainer reference (no participant details)',
			'training_content' => 'Implementation training covered (no participant or clinical details)',
			'review_note' => 'Review explanation / gaps (no participant details)',
		);
	}
	public static function states(): array {
		return array( 'none' => 'Use course evidence', 'managed' => 'External evidence — agency review required', 'submitted' => 'Evidence submitted — pending review', 'verified' => 'Completed — manually verified' );
	}
	public static function sanitize( $input, array $current, int $user_id ) {
		if ( ! is_array( $input ) || ! is_string( $input['state'] ?? null ) || ! array_key_exists( $input['state'], self::states() ) ) { return new WP_Error( 'isp_review', 'Select a supported ISP evidence state.' ); }
		$result = array( 'state' => $input['state'] );
		foreach ( self::fields() as $key => $label ) {
			if ( isset( $input[ $key ] ) && ! is_string( $input[ $key ] ) ) { return new WP_Error( 'isp_review', 'Invalid ISP evidence field.' ); }
			$result[ $key ] = trim( sanitize_text_field( $input[ $key ] ?? '' ) );
			if ( strlen( $result[ $key ] ) > 1000 ) { return new WP_Error( 'isp_review', 'ISP evidence fields must not exceed 1000 bytes.' ); }
		}
		foreach ( array( 'evidence_reference', 'scope_reference' ) as $key ) {
			if ( '' !== $result[ $key ] && ( ! preg_match( '/^[A-Za-z0-9._:\/-]{1,100}$/D', $result[ $key ] ) || false !== strpos( $result[ $key ], '://' ) || 0 === strpos( $result[ $key ], '//' ) ) ) { return new WP_Error( 'isp_review', 'Use opaque record/register identifiers of up to 100 characters, not names, spaces or URLs.' ); }
		}
		$state = $result['state'];
		if ( in_array( $state, array( 'submitted', 'verified' ), true ) && ( '' === $result['evidence_reference'] || '' === $result['scope_reference'] ) ) { return new WP_Error( 'isp_review', 'Submitted ISP evidence needs a secure record ID and an assigned-plan register/version reference.' ); }
		if ( 'verified' === $state ) {
			foreach ( self::fields() as $key => $label ) {
				if ( '' === $result[ $key ] ) { return new WP_Error( 'isp_review', 'Verification requires every training and review field, not only a signature.' ); }
			}
		}
		if ( '' !== $result['duration_minutes'] && ( ! ctype_digit( $result['duration_minutes'] ) || (int) $result['duration_minutes'] < 1 || (int) $result['duration_minutes'] > 525600 ) ) { return new WP_Error( 'isp_review', 'Enter a positive whole-number training duration in minutes.' ); }
		if ( '' !== $result['training_date'] ) {
			$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $result['training_date'], wp_timezone() );
			$window = GHCA_Audit_Calculator::resolve_annual_window( GHCA_ACD_Employment_Record::timestamp( $user_id ), GHCA_ACD_Settings::get_configured_annual_cycle(), time(), wp_timezone() );
			if ( ! $date || $date->format( 'Y-m-d' ) !== $result['training_date'] || $date->getTimestamp() < $window['start_ts'] || $date->getTimestamp() >= $window['end_ts'] || $date->getTimestamp() > time() ) { return new WP_Error( 'isp_review', 'Training date must be a valid, nonfuture date within this employee training period.' ); }
		}
		if ( 'verified' === $state ) {
			$unchanged = array_intersect_key( $current, $result ) === $result && ! empty( $current['verified_by'] ) && ! empty( $current['verified_at'] );
			if ( ! $unchanged && ( $input['confirm_review'] ?? '' ) !== '1' ) { return new WP_Error( 'isp_review', 'Confirm that the evidence substantiates implementation training for the employee and full referenced plan scope. A signature alone is insufficient.' ); }
			$result['verified_by'] = $unchanged ? (int) $current['verified_by'] : get_current_user_id();
			$result['verified_at'] = $unchanged ? (int) $current['verified_at'] : time();
		}
		return $result;
	}
	public static function status( array $record ): string {
		$state = $record['state'] ?? 'none';
		if ( 'verified' === $state && ( empty( $record['verified_by'] ) || empty( $record['verified_at'] ) ) ) { return self::states()['managed']; }
		return self::states()[ $state ] ?? self::states()['managed'];
	}
		public static function render( array $record ): void {
		echo '<h3>ISP implementation — agency-held evidence</h3><p>Keep signed forms in the agency\'s secure records. This screen stores references only; no file is uploaded or attached to the packet. The scope register must identify all relevant individuals, plan versions and this employee\'s training records. Re-review when assignments or plans change. An acknowledgment alone does not establish implementation training. No hours are awarded by this review.</p>';
		echo '<p>Temporary external-evidence route: select External evidence — agency review required and save the profile. Training-detail fields may remain blank for this option. Supply the supporting records separately through an appropriate secure channel. This does not verify training, attach documents or mark the employee compliant.</p>';
		echo '<label>Evidence handling <select name="ghca_isp_evidence[state]">';
		foreach ( self::states() as $state => $label ) { echo '<option value="' . esc_attr( $state ) . '" ' . selected( $record['state'] ?? 'none', $state, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></label>';
		foreach ( self::fields() as $key => $label ) {
			$type = 'training_date' === $key ? 'date' : ( 'duration_minutes' === $key ? 'number' : 'text' );
			echo '<p><label>' . esc_html( $label ) . '<br><input class="regular-text" type="' . esc_attr( $type ) . '" maxlength="1000" ' . ( 'number' === $type ? 'min="1" max="525600" step="1"' : '' ) . ' name="ghca_isp_evidence[' . esc_attr( $key ) . ']" value="' . esc_attr( $record[ $key ] ?? '' ) . '"></label></p>';
		}
		if ( ! empty( $record['verified_by'] ) && ! empty( $record['verified_at'] ) ) { echo '<p>Verified by user #' . (int) $record['verified_by'] . ' on ' . esc_html( wp_date( 'Y-m-d H:i', (int) $record['verified_at'] ) ) . '.</p>'; }
		echo '<p><label><input type="checkbox" name="ghca_isp_evidence[confirm_review]" value="1"> I reviewed the evidence and confirm it documents implementation training for this employee across the full referenced plan scope, not just receipt/signature. Required for a new or changed verification.</label></p>';
	}
}
