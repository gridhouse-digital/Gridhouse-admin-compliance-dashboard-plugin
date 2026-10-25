<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Agency attestations, bound to an employee and exact annual/reporting period. */
final class GHCA_Agency_Training {
    const OPTION = 'ghca_acd_agency_training';
    const META = 'ghca_acd_agency_training_history';

    public static function init(): void {
        add_action( 'show_user_profile', array( __CLASS__, 'render' ) );
        add_action( 'edit_user_profile', array( __CLASS__, 'render' ) );
        add_action( 'user_profile_update_errors', array( __CLASS__, 'validate' ), 10, 3 );
        // WordPress validates the entire profile before this successful-update hook.
        add_action( 'profile_update', array( __CLASS__, 'save' ) );
        add_action( 'wp_ajax_ghca_acd_agency_training', array( __CLASS__, 'ajax' ) );
    }

    public static function topics(): array {
        return array( 'isp' => 'Implementation of Individual Plan (ISP)', 'communication' => 'Communication Profile Training' );
    }

    public static function settings(): array {
        $value = get_option( self::OPTION, array() );
        return array_merge( array( 'isp_hours' => '1', 'communication_hours' => '1', 'communication_enabled' => '0' ), is_array( $value ) ? $value : array() );
    }

    public static function sanitize_settings( $value ): array {
        $old = self::settings();
        if ( ! is_array( $value ) || ! current_user_can( 'manage_options' ) ) { return $old; }
        $result = array();
        foreach ( array( 'isp_hours', 'communication_hours' ) as $key ) {
            if ( ! is_scalar( $value[$key] ?? null ) || ! preg_match( '/^(?:[0-9]{1,3})(?:\.[0-9]{1,2})?$/D', (string) $value[$key] ) || (float) $value[$key] <= 0 || (float) $value[$key] > 100 ) {
                add_settings_error( self::OPTION, 'hours', 'Enter training hours greater than 0 and no more than 100, with up to two decimal places. Previous defaults retained.' );
                return $old;
            }
            $result[$key] = (string) $value[$key];
        }
        if ( ! in_array( $value['communication_enabled'] ?? null, array( '0', '1' ), true ) ) { return $old; }
        $result['communication_enabled'] = $value['communication_enabled'];
        return $result;
    }

    public static function enabled( string $topic ): bool {
        return 'isp' === $topic
            ? in_array( get_option( GHCA_ACD_Settings::OPTION_ISP_MANUAL_DELIVERY, 0 ), array( 1, '1' ), true )
            : '1' === self::settings()['communication_enabled'];
    }

    public static function settings_fields(): void {
        $settings = self::settings();
        echo '<tr><th scope="row">Agency-delivered training credit</th><td>';
        foreach ( self::topics() as $topic => $label ) {
            echo '<p><label>' . esc_html( $label ) . ' — default hours <input type="number" min="0.01" max="100" step="0.01" name="' . esc_attr( self::OPTION . '[' . $topic . '_hours]' ) . '" value="' . esc_attr( $settings[$topic . '_hours'] ) . '" class="small-text"></label></p>';
        }
        echo '<input type="hidden" name="' . esc_attr( self::OPTION ) . '[communication_enabled]" value="0"><label><input type="checkbox" name="' . esc_attr( self::OPTION ) . '[communication_enabled]" value="1" ' . checked( $settings['communication_enabled'], '1', false ) . '> Communication Profile Training is conducted by the agency</label>';
        echo '<p class="description">Enable ISP delivery above as applicable. Confirm completed training in Dashboard → Employee drawer → Administration → Edit Records, for the employee and exact annual/reporting dates. Defaults apply to new confirmations only; settings alone do not award hours. No upload is required. Supporting records remain with the agency.</p></td></tr>';
    }

    public static function history( int $user_id ): array {
        return array_values( array_filter( (array) get_user_meta( $user_id, self::META, false ), 'is_array' ) );
    }

    public static function current( int $user_id, string $start, string $end ): array {
        $current = array();
        foreach ( self::history( $user_id ) as $record ) {
            if ( ( $record['start'] ?? '' ) !== $start || ( $record['end'] ?? '' ) !== $end || empty( $record['by'] ) || empty( $record['at'] ) || ! is_array( $record['topics'] ?? null ) ) { continue; }
            foreach ( $record['topics'] as $topic => $row ) {
                if ( isset( self::topics()[$topic] ) && is_array( $row ) ) { $current[$topic] = $row + array( 'by' => $record['by'], 'at' => $record['at'] ); }
            }
        }
        return $current;
    }

    /** Credit is never inferred from delivery settings or a different period. */
    public static function credited( int $user_id, int $start, int $end ): array {
        if ( $start <= 0 || $end <= $start ) { return array(); }
        $records = self::current( $user_id, wp_date( 'Y-m-d', $start ), wp_date( 'Y-m-d', $end - 1 ) );
        return array_filter( $records, static function ( $row ) {
            return ( $row['state'] ?? '' ) === 'confirmed' && is_numeric( $row['hours'] ?? null ) && (float) $row['hours'] > 0 && (float) $row['hours'] <= 100;
        } );
    }

    public static function can_edit( int $user_id, bool $drawer = false ): bool {
        return $user_id > 0 && ( $drawer
            ? GHCA_ACD_Roles::user_can_edit_records() && GHCA_ACD_User_Report::can_view_user( $user_id )
            : current_user_can( 'manage_options' ) && current_user_can( 'edit_user', $user_id ) );
    }

    public static function submission( int $user_id, bool $drawer = false ) {
        $input = $_POST['ghca_agency_training'] ?? null;
        $nonce = $_POST['ghca_agency_training_nonce'] ?? null;
        if ( ! self::can_edit( $user_id, $drawer ) || ! is_string( $nonce ) || ! wp_verify_nonce( wp_unslash( $nonce ), 'ghca_agency_training_' . $user_id ) ) { return new WP_Error( 'agency_training', 'Not authorized to confirm agency training.' ); }
        if ( ! is_array( $input ) ) { return new WP_Error( 'agency_training', 'Invalid agency training form.' ); }
        $input = wp_unslash( $input );
        $actions = $input['actions'] ?? null;
        if ( ! is_array( $actions ) || array_diff( array_keys( $actions ), array_keys( self::topics() ) ) ) { return new WP_Error( 'agency_training', 'Invalid training actions.' ); }
        $changes = array();
        foreach ( self::topics() as $topic => $label ) {
            $action = $actions[$topic] ?? null;
            if ( ! in_array( $action, array( 'unchanged', 'confirmed', 'withdrawn' ), true ) ) { return new WP_Error( 'agency_training', 'Select a valid training action.' ); }
            if ( 'unchanged' !== $action ) { $changes[$topic] = $action; }
        }
        if ( ! $changes ) { return array(); }
        if ( ( $input['confirm'] ?? '' ) !== '1' ) { return new WP_Error( 'agency_training', 'Confirm the training declaration before saving.' ); }
        if ( ! is_string( $input['revision'] ?? null ) || ! hash_equals( hash( 'sha256', json_encode( self::history( $user_id ) ) ), $input['revision'] ) ) { return new WP_Error( 'agency_training', 'Agency training changed. Reload before confirming.' ); }
        if ( ! is_string( $input['settings_revision'] ?? null ) || ! hash_equals( self::settings_revision(), $input['settings_revision'] ) ) { return new WP_Error( 'agency_training', 'Training defaults changed. Reload to review the hours before confirming.' ); }
        try { $window = GHCA_Audit_Calculator::reporting_window( array( 'start' => $input['start'] ?? null, 'end' => $input['end'] ?? null ) ); }
        catch ( InvalidArgumentException $error ) { return new WP_Error( 'agency_training', $error->getMessage() ); }
        if ( $window['start_ts'] > time() ) { return new WP_Error( 'agency_training', 'Cannot confirm training for a future period.' ); }
        $current = self::current( $user_id, $input['start'], $input['end'] );
        $topics = array();
        $settings = self::settings();
        foreach ( $changes as $topic => $state ) {
            if ( 'confirmed' === $state && ! self::enabled( $topic ) ) { return new WP_Error( 'agency_training', 'Enable this agency-delivered training in Settings before confirming it.' ); }
            $hours = 'confirmed' === $state ? (float) $settings[$topic . '_hours'] : 0;
            if ( 'confirmed' === $state && ( ! is_finite( $hours ) || $hours <= 0 || $hours > 100 ) ) { return new WP_Error( 'agency_training', 'Invalid configured training hours.' ); }
            // Reconfirming is idempotent: changing a default does not rewrite an existing confirmation.
            if ( ( $current[$topic]['state'] ?? '' ) === $state ) { continue; }
            $topics[$topic] = array( 'state' => $state, 'hours' => $hours );
        }
        return $topics ? array( 'start' => $input['start'], 'end' => $input['end'], 'topics' => $topics, 'by' => get_current_user_id(), 'at' => time() ) : array();
    }

    public static function validate( $errors, $update, $user ): void {
        if ( ! isset( $_POST['ghca_agency_training'] ) ) { return; }
        $result = self::submission( (int) $user->ID );
        if ( is_wp_error( $result ) ) { $errors->add( 'agency_training', $result->get_error_message() ); }
    }

    public static function save( $user_id ): void {
        if ( ! isset( $_POST['ghca_agency_training'] ) ) { return; }
        $result = self::update( (int) $user_id );
        if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ) ); }
    }

    public static function update( int $user_id, bool $drawer = false ) {
        $result = self::submission( $user_id, $drawer );
        if ( is_wp_error( $result ) ) { return $result; }
        if ( $result && ! add_user_meta( $user_id, self::META, $result ) ) { return new WP_Error( 'agency_training', 'Agency training could not be saved. Reload and retry.' ); }
        return true;
    }

    public static function render( $user, ?array $window = null ): void {
        $drawer = null !== $window;
        if ( ! self::can_edit( (int) $user->ID, $drawer ) ) { return; }
        $window = $window ?? GHCA_Audit_Calculator::resolve_annual_window( GHCA_ACD_Employment_Record::timestamp( (int) $user->ID ), GHCA_ACD_Settings::get_configured_annual_cycle(), time(), wp_timezone() );
        echo '<h2>Agency-delivered training</h2><p>For annual packets and custom-date reports. Enter the exact inclusive packet dates. Confirm only training already completed by this employee in that period. No upload required. Other periods and orientation packets are unchanged.</p>';
        wp_nonce_field( 'ghca_agency_training_' . $user->ID, 'ghca_agency_training_nonce' );
        echo '<input type="hidden" name="ghca_agency_training[revision]" value="' . esc_attr( hash( 'sha256', json_encode( self::history( (int) $user->ID ) ) ) ) . '">';
        echo '<input type="hidden" name="ghca_agency_training[settings_revision]" value="' . esc_attr( self::settings_revision() ) . '">';
        foreach ( array( 'start' => 'Period start', 'end' => 'Period end (inclusive)' ) as $key => $label ) {
            $ts = $window[$key . '_ts'];
            echo '<p><label>' . esc_html( $label ) . ' <input type="date" ' . ( $drawer ? 'readonly' : '' ) . ' name="ghca_agency_training[' . esc_attr( $key ) . ']" value="' . esc_attr( $ts ? wp_date( 'Y-m-d', 'end' === $key ? $ts - 1 : $ts ) : '' ) . '"></label></p>';
        }
        $current = self::current( (int) $user->ID, $window['start_ts'] ? wp_date( 'Y-m-d', $window['start_ts'] ) : '', $window['end_ts'] ? wp_date( 'Y-m-d', $window['end_ts'] - 1 ) : '' );
        foreach ( self::topics() as $topic => $label ) {
            $saved = $current[$topic] ?? array();
            echo '<p class="ghca-agency-saved"><strong>' . esc_html( $label ) . '</strong><br>Saved status: ' . esc_html( empty( $saved ) ? 'Not confirmed for these dates' : ( 'confirmed' === $saved['state'] ? 'Completed in person — ' . $saved['hours'] . ' confirmed hours' : 'Confirmation withdrawn' ) . '; reviewer #' . $saved['by'] . '; ' . wp_date( 'Y-m-d H:i', $saved['at'] ) ) . '</p>';
            echo '<p><label>' . esc_html( $label . ' (' . self::settings()[$topic . '_hours'] . ' hours for a new confirmation)' ) . ' <select name="ghca_agency_training[actions][' . esc_attr( $topic ) . ']"><option value="unchanged">No change</option><option value="confirmed">Confirm completed in person</option><option value="withdrawn">Withdraw confirmation for these dates</option></select></label></p>';
        }
        echo '<p><label><input type="checkbox" name="ghca_agency_training[confirm]" value="1"> I confirm the selected changes for this employee and period. For completion: training was delivered, the configured hours are supported by agency records, ISP covers the applicable plans (not just receipt of a form), and these hours are not also recorded as a course or external training entry.</label></p><p>' . ( $drawer ? 'Save confirmations below.' : 'Save with Update User.' ) . ' No change leaves saved training intact. Confirmed hours remain subject to packet rules preventing duplicate credit. Previous confirmations and withdrawals are retained. To correct confirmed hours, withdraw first, then confirm again with the corrected setting.</p><details><summary>Confirmation history</summary><ul>';
        foreach ( array_reverse( self::history( (int) $user->ID ) ) as $record ) {
            foreach ( (array) ( $record['topics'] ?? array() ) as $topic => $row ) {
                if ( ! is_array( $row ) ) { continue; }
                echo '<li>' . esc_html( ( self::topics()[$topic] ?? $topic ) . ': ' . ( $row['state'] ?? '' ) . ', ' . ( $row['hours'] ?? 0 ) . ' hours; ' . ( $record['start'] ?? '' ) . ' to ' . ( $record['end'] ?? '' ) . '; reviewer #' . (int) ( $record['by'] ?? 0 ) . '; ' . wp_date( 'Y-m-d H:i', (int) ( $record['at'] ?? 0 ) ) ) . '</li>';
            }
        }
        echo '</ul></details>';
    }

    public static function settings_revision(): string {
        return hash( 'sha256', json_encode( array( self::settings(), self::enabled( 'isp' ) ) ) );
    }

    /** Independent drawer saves; never submit the foundational-record form. */
    public static function ajax(): void {
        check_ajax_referer( 'ghca_acd_table', 'nonce' );
        $id = isset( $_POST['user_id'] ) && is_string( $_POST['user_id'] ) && ctype_digit( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
        if ( ! self::can_edit( $id, true ) ) { wp_send_json_error( array( 'message' => 'Not authorized to edit this employee.' ), 403 ); return; }
        $operation = $_POST['operation'] ?? 'load';
        if ( ! in_array( $operation, array( 'load', 'training', 'review' ), true ) ) { wp_send_json_error( array( 'message' => 'Invalid action.' ), 400 ); return; }
        $mode = $_POST['mode'] ?? 'cycle';
        if ( ! in_array( $mode, array( 'cycle', 'custom' ), true ) ) { wp_send_json_error( array( 'message' => 'Invalid period mode.' ), 400 ); return; }
        try {
            $window = 'custom' === $mode ? GHCA_Audit_Calculator::reporting_window( array( 'start' => $_POST['start'] ?? null, 'end' => $_POST['end'] ?? null ) )
                : GHCA_Audit_Calculator::resolve_annual_window( GHCA_ACD_Employment_Record::timestamp( $id ), GHCA_ACD_Settings::get_configured_annual_cycle(), time(), wp_timezone() );
        } catch ( InvalidArgumentException $error ) { wp_send_json_error( array( 'message' => $error->getMessage() ), 400 ); return; }
        if ( 'training' === $operation ) {
            $input = $_POST['ghca_agency_training'] ?? null;
            if ( ! $window['start_ts'] || ! is_array( $input ) || ( $input['start'] ?? null ) !== wp_date( 'Y-m-d', $window['start_ts'] ) || ( $input['end'] ?? null ) !== wp_date( 'Y-m-d', $window['end_ts'] - 1 ) ) {
                wp_send_json_error( array( 'message' => 'Reporting dates changed. Reload confirmations before saving.' ), 409 ); return;
            }
            $result = self::update( $id, true );
        } elseif ( 'review' === $operation ) { $result = GHCA_ODP_Applicability::update( $id, true ); }
        else { $result = true; }
        if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 ); return; }
        ob_start();
        $user = (object) array( 'ID' => $id );
        if ( $window['start_ts'] ) {
            echo '<form data-ghca-agency-form="training">';
            self::render( $user, $window );
            echo '<button type="submit" class="ghca-acd__edit-btn ghca-acd__edit-btn--save">Save confirmations</button></form>';
        } else { echo '<p>Save the annual cycle and verified employment date, or select custom reporting dates above.</p>'; }
        echo '<details><summary>Advanced review — current annual cycle</summary><form data-ghca-agency-form="review">';
        GHCA_ODP_Applicability::render( $user, true );
        if ( '' !== GHCA_ODP_Applicability::period( $id ) ) { echo '<button type="submit" class="ghca-acd__edit-btn ghca-acd__edit-btn--save">Save applicability review</button>'; }
        echo '</form></details>';
        wp_send_json_success( array( 'html' => ob_get_clean(), 'message' => 'load' === $operation ? 'Saved records loaded.' : 'Saved. The records below have been refreshed.' ) );
    }
}
