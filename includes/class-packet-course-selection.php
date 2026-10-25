<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Presentation selection only. Never awards credit or changes applicability. */
final class GHCA_Packet_Course_Selection {
    const OPTION = 'ghca_acd_packet_selected_courses';

    public static function register(): void {
        register_setting( 'ghca_acd_packet_selection', self::OPTION, array( 'type' => 'array', 'default' => array(), 'show_in_rest' => false, 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
    }

    public static function choices(): array {
        $choices = array();
        foreach ( get_posts( array( 'post_type' => 'sfwd-courses', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) ) as $course ) {
            $choices['learndash:' . (int) $course->ID] = 'LearnDash · ' . $course->post_title;
        }
        if ( class_exists( 'GHCA_ACD_Jotform_Repository' ) ) {
            foreach ( GHCA_ACD_Jotform_Repository::catalog( true ) as $item ) {
                $choices['external:' . (int) $item['id']] = 'External · ' . $item['title'];
            }
        }
        return $choices;
    }

    public static function sanitize( $value ): array {
        $old = get_option( self::OPTION, array() );
        $old = is_array( $old ) ? $old : array();
        if ( ! current_user_can( 'manage_options' ) || ! is_array( $value ) || ( $value['complete'] ?? '' ) !== '1' ) { return $old; }
        $choices = self::choices();
        // WordPress may sanitize twice when creating an option for the first time.
        $saved = array( 'complete' => '1' );
        foreach ( array( 'annual', 'orientation' ) as $mode ) {
            if ( ! isset( $value[$mode] ) || ! is_array( $value[$mode] ) || count( $value[$mode] ) > 31 ) {
                add_settings_error( self::OPTION, 'invalid_selection', __( 'Course selection was incomplete or too large. Previous selections retained.', 'ghca-acd' ) );
                return $old;
            }
            $saved[$mode] = array();
            foreach ( $value[$mode] as $index => $key ) {
                if ( is_string( $index ) && preg_match( '/^(learndash|external):[1-9][0-9]*$/D', $index ) ) { $key = $index; }
                if ( '' === $key ) { continue; }
                if ( ! is_string( $key ) || ( ! isset( $choices[$key] ) && ! isset( $old[$mode][$key] ) ) ) {
                    add_settings_error( self::OPTION, 'invalid_course', __( 'Invalid course selection. Previous selections retained.', 'ghca-acd' ) );
                    return $old;
                }
                $saved[$mode][$key] = sanitize_text_field( $choices[$key] ?? $old[$mode][$key] );
            }
            if ( count( $saved[$mode] ) > 30 ) {
                add_settings_error( self::OPTION, 'selection_limit', __( 'Select no more than 30 courses per packet type. Previous selections retained.', 'ghca-acd' ) );
                return $old;
            }
        }
        return $saved;
    }

    public static function rows( array $courses, string $mode ): array {
        $saved = get_option( self::OPTION, array() );
        $selected = is_array( $saved[$mode] ?? null ) ? $saved[$mode] : array();
        $rows = array();
        foreach ( $selected as $key => $title ) {
            if ( ! is_string( $key ) || ! preg_match( '/^(learndash|external):[1-9][0-9]*$/D', $key ) || ! is_string( $title ) ) { continue; }
            // Source labels help administrators distinguish choices, but are not packet course titles.
            $prefix = strpos( $key, 'learndash:' ) === 0 ? 'LearnDash · ' : 'External · ';
            $packet_title = strpos( $title, $prefix ) === 0 ? substr( $title, strlen( $prefix ) ) : $title;
            $row = array( 'title' => $packet_title, 'hours' => 0, 'date' => 0, 'included' => false );
            $seen = array();
            foreach ( $courses as $course ) {
                $match = 'external' === ( $course['source'] ?? '' ) ? 'external:' . (int) ( $course['catalog_id'] ?? 0 ) : 'learndash:' . (int) ( $course['course_id'] ?? 0 );
                $identity = (string) ( $course['course_key'] ?? $match );
                if ( $match !== $key || isset( $seen[$identity] ) ) { continue; }
                $seen[$identity] = true;
                $row['included'] = true;
                $row['hours'] += (float) $course['credit_hours'];
                $row['date'] = max( $row['date'], (int) $course['date'] );
            }
            $rows[] = $row;
        }
        return $rows;
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $choices = self::choices();
        $saved = get_option( self::OPTION, array() );
        echo '<section id="ghca-packet-selection"><h2>' . esc_html__( 'Agency-selected packet courses', 'ghca-acd' ) . '</h2><p>' . esc_html__( 'Add course rows below the ODP topics. Select up to 30 per packet type. Rows summarize included evidence; they do not assign courses, add hours or create requirements. Annual selections also apply to custom-date reports.', 'ghca-acd' ) . '</p><form method="post" action="options.php">';
        settings_fields( 'ghca_acd_packet_selection' );
        foreach ( array( 'annual' => 'Annual & custom-date reports', 'orientation' => 'Orientation packets' ) as $mode => $label ) {
            $selected = is_array( $saved[$mode] ?? null ) ? $saved[$mode] : array();
            echo '<fieldset class="ghca-course-picker"><legend>' . esc_html( $label ) . '</legend><input type="hidden" name="' . esc_attr( self::OPTION . '[' . $mode . '][]' ) . '" value="">';
            foreach ( $choices + $selected as $key => $title ) {
                echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION . '[' . $mode . '][]' ) . '" value="' . esc_attr( $key ) . '" ' . checked( isset( $selected[$key] ), true, false ) . '> ' . esc_html( $title . ( isset( $choices[$key] ) ? '' : ' — unavailable in current catalog' ) ) . '</label>';
            }
            if ( ! $choices && ! $selected ) { echo '<p>' . esc_html__( 'No published courses or active external catalog entries.', 'ghca-acd' ) . '</p>'; }
            echo '</fieldset>';
        }
        echo '<input type="hidden" name="' . esc_attr( self::OPTION . '[complete]' ) . '" value="1">';
        submit_button( __( 'Save packet course selections', 'ghca-acd' ) );
        echo '</form></section>';
    }
}
