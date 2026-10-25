<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-isp-evidence-review.php';

/** Administrator-reviewed, period-specific applicability. No inferred exemptions. */
final class GHCA_ODP_Applicability {
	const META_KEY = 'ghca_acd_odp_applicability_review';
	public static function init(): void {
		add_action( 'edit_user_profile', array( __CLASS__, 'render' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'render' ) );
		add_action( 'user_profile_update_errors', array( __CLASS__, 'validate' ), 10, 3 );
		add_action( 'profile_update', array( __CLASS__, 'save' ) );
	}
	public static function categories(): array {
		return array( 'behavior_supports' => 'Behavior supports — works directly with an individual', 'individual_plan' => 'Individual plan — provides an HCBS or base-funded service' );
	}
	public static function period( int $user_id ): string {
		$window = GHCA_Audit_Calculator::resolve_annual_window( GHCA_ACD_Employment_Record::timestamp( $user_id ), GHCA_ACD_Settings::get_configured_annual_cycle(), time(), wp_timezone() );
		return $window['start_ts'] > 0 ? $window['start_date'] . ' to ' . $window['end_date'] : '';
	}
	public static function current( int $user_id, string $period ): array {
		$result = array();
		if ( '' === $period ) { return $result; }
		foreach ( (array) get_user_meta( $user_id, self::META_KEY, false ) as $review ) {
			if ( is_array( $review ) && ( $review['period'] ?? '' ) === $period && ! empty( $review['reviewed_by'] ) && ! empty( $review['reviewed_at'] ) ) {
				$result = is_array( $review['decisions'] ?? null ) ? $review['decisions'] : array();
			}
		}
		return $result;
	}
	/** Return validated decisions, or an error. Called before any persistence. */
	public static function decisions( $input ) {
		if ( ! is_array( $input ) ) { return new WP_Error( 'odp_review', 'Invalid applicability review.' ); }
		$result = array();
		foreach ( self::categories() as $category => $label ) {
			$row = $input[ $category ] ?? array();
			$state = is_array( $row ) ? ( $row['state'] ?? 'review' ) : null;
			$reason = is_array( $row ) && is_string( $row['reason'] ?? null ) ? trim( sanitize_text_field( $row['reason'] ) ) : '';
			if ( ! in_array( $state, array( 'review', 'applicable', 'not_applicable' ), true ) || strlen( $reason ) > 1000 || ( 'review' !== $state && '' === $reason ) ) {
				return new WP_Error( 'odp_review', 'Each applicability decision requires a reason/source (maximum 1000 bytes). No review was saved.' );
			}
			$result[ $category ] = array( 'state' => $state, 'reason' => $reason );
		}
		return $result;
	}
	private static function submitted( int $user_id, bool $drawer = false ) {
		if ( ! GHCA_Agency_Training::can_edit( $user_id, $drawer ) || ! isset( $_POST['ghca_odp_review_nonce'] ) || ! is_string( $_POST['ghca_odp_review_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['ghca_odp_review_nonce'] ), 'ghca_odp_review_' . $user_id ) ) {
			return new WP_Error( 'odp_review', 'Not authorized to save applicability.' );
		}
		$period = self::period( $user_id );
		if ( $drawer && ( ! is_array( $_POST['ghca_odp_decisions'] ?? null ) || array_diff( array_keys( self::categories() ), array_keys( $_POST['ghca_odp_decisions'] ) ) ) ) { return new WP_Error( 'odp_review', 'Incomplete applicability form. Reload before saving.' ); }
		if ( '' === $period || ! isset( $_POST['ghca_odp_period'] ) || wp_unslash( $_POST['ghca_odp_period'] ) !== $period ) {
			return new WP_Error( 'odp_review', 'The training period changed or is unavailable. Reload before reviewing applicability.' );
		}
		$decisions = self::decisions( wp_unslash( $_POST['ghca_odp_decisions'] ?? array() ) );
		if ( is_wp_error( $decisions ) ) { return $decisions; }
		$current = self::current( $user_id, $period );
		if ( $drawer && ( isset( $_POST['ghca_isp_evidence'] ) || ! is_string( $_POST['ghca_odp_revision'] ?? null ) || ! hash_equals( hash( 'sha256', json_encode( $current ) ), $_POST['ghca_odp_revision'] ) ) ) { return new WP_Error( 'odp_review', 'The review changed or contains unsupported fields. Reload before saving.' ); }
		if ( isset( $_POST['ghca_isp_evidence'] ) ) {
			if ( ! is_string( $_POST['ghca_odp_revision'] ?? null ) || ! hash_equals( hash( 'sha256', json_encode( $current ) ), $_POST['ghca_odp_revision'] ) ) { return new WP_Error( 'isp_review', 'The review changed since this page loaded. Reload before saving.' ); }
			$manual = GHCA_ISP_Evidence_Review::sanitize( wp_unslash( $_POST['ghca_isp_evidence'] ), $current['isp_evidence'] ?? array(), $user_id );
			if ( is_wp_error( $manual ) ) { return $manual; }
			$decisions['isp_evidence'] = $manual;
		} elseif ( isset( $current['isp_evidence'] ) ) {
			$decisions['isp_evidence'] = $current['isp_evidence'];
		}
		$manual_state = $decisions['isp_evidence']['state'] ?? 'none';
		if ( 'none' !== $manual_state && 'not_applicable' === $decisions['individual_plan']['state'] ) { return new WP_Error( 'isp_review', 'Agency-managed evidence is not an exemption. Resolve ISP applicability or choose course evidence before saving Not applicable.' ); }
		if ( 'verified' === $manual_state && 'applicable' !== $decisions['individual_plan']['state'] ) { return new WP_Error( 'isp_review', 'Confirm that Individual Plan training applies, with a reason, before verifying its evidence.' ); }
		return $decisions;
	}
	public static function validate( $errors, $update, $user ): void {
		if ( ! isset( $_POST['ghca_odp_decisions'] ) ) { return; }
		$result = self::submitted( (int) $user->ID );
		if ( is_wp_error( $result ) ) { $errors->add( 'odp_review', $result->get_error_message() ); }
	}
	public static function save( $user_id ): void {
		if ( ! isset( $_POST['ghca_odp_decisions'] ) ) { return; }
		$result = self::update( (int) $user_id );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ) ); }
	}
	public static function update( int $user_id, bool $drawer = false ) {
		$decisions = self::submitted( $user_id, $drawer );
		if ( is_wp_error( $decisions ) ) { return $decisions; }
		$period = self::period( $user_id );
		if ( $decisions === self::current( $user_id, $period ) ) { return true; }
		if ( ! add_user_meta( $user_id, self::META_KEY, array( 'period' => $period, 'decisions' => $decisions, 'reviewed_by' => get_current_user_id(), 'reviewed_at' => time() ) ) ) {
			return new WP_Error( 'odp_review', 'Applicability review could not be saved. Reload and retry.' );
		}
		return true;
	}
	public static function render( $user, bool $drawer = false ): void {
		if ( ! GHCA_Agency_Training::can_edit( (int) $user->ID, $drawer ) ) { return; }
		$period = self::period( (int) $user->ID );
		echo '<h2>ODP annual applicability review</h2><p>Applies only to this employee and current training period. Does not change training hours or the agency cycle. Re-review when duties change. Previous decisions are retained.</p>';
		if ( '' === $period ) { echo '<p>Configure the annual cycle and verified employment date first.</p>'; return; }
		$decisions = self::current( (int) $user->ID, $period );
		echo '<p>' . esc_html( $period ) . '</p>';
		wp_nonce_field( 'ghca_odp_review_' . $user->ID, 'ghca_odp_review_nonce' );
		echo '<input type="hidden" name="ghca_odp_period" value="' . esc_attr( $period ) . '">';
		echo '<input type="hidden" name="ghca_odp_revision" value="' . esc_attr( hash( 'sha256', json_encode( $decisions ) ) ) . '">';
		foreach ( self::categories() as $category => $label ) {
			echo '<p><label>' . esc_html( $label ) . ' <select name="ghca_odp_decisions[' . esc_attr( $category ) . '][state]">';
			foreach ( array( 'review' => 'Needs review', 'applicable' => 'Applies', 'not_applicable' => 'Not applicable' ) as $state => $text ) {
				echo '<option value="' . esc_attr( $state ) . '" ' . selected( $decisions[ $category ]['state'] ?? 'review', $state, false ) . '>' . esc_html( $text ) . '</option>';
			}
			echo '</select></label><br><label>Reason and source <input class="regular-text" maxlength="1000" name="ghca_odp_decisions[' . esc_attr( $category ) . '][reason]" value="' . esc_attr( $decisions[ $category ]['reason'] ?? '' ) . '"></label></p>';
		}
		if ( ! $drawer ) {
			echo '<details><summary>Legacy detailed ISP evidence review (optional)</summary>';
			GHCA_ISP_Evidence_Review::render( $decisions['isp_evidence'] ?? array() );
			echo '</details>';
		} elseif ( ! empty( $decisions['isp_evidence'] ) ) {
			echo '<p>Existing detailed ISP evidence is retained unchanged. Contact Gridhouse support to amend that legacy review.</p>';
		}
	}
}
