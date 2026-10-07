<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Verified employment input, independent of account registration and access roles. */
final class GHCA_ACD_Employment_Record {
  const META_KEY = 'ghca_acd_verified_employment';

  public static function date_timestamp( $date ): int {
    if ( ! is_string( $date ) || ! preg_match( '/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', $date ) ) { return 0; }
    $timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
    $parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $timezone );
    return $parsed && $parsed->format( 'Y-m-d' ) === $date ? $parsed->getTimestamp() : 0;
  }

  public static function get( int $user_id ): array {
    $record = get_user_meta( $user_id, self::META_KEY, true );
    if ( ! is_array( $record ) || self::date_timestamp( $record['date'] ?? null ) <= 0 || ! is_string( $record['source'] ?? null ) || '' === trim( $record['source'] ) || empty( $record['verified_by'] ) || empty( $record['verified_at'] ) ) { return array(); }
    return $record;
  }

  public static function timestamp( int $user_id ): int {
    return self::date_timestamp( self::get( $user_id )['date'] ?? null );
  }

  /** Called after Edit Records nonce/capability/employee-scope authorization. */
  public static function save_from_input( int $user_id, array $input ) {
    if ( ! array_key_exists( 'employment_date', $input ) && ! array_key_exists( 'employment_source', $input ) ) { return false; }
    $date = $input['employment_date'] ?? null;
    $source = $input['employment_source'] ?? null;
    $old = self::get( $user_id );
    if ( '' === $date && '' === $source && ! $old ) { return false; }
    if ( ! is_string( $source ) || strlen( $source ) > 500 || self::date_timestamp( $date ) <= 0 ) {
      return new WP_Error( 'employment_invalid', __( 'Use a valid employment date and a source reference of at most 500 bytes.', 'ghca-acd' ) );
    }
    $source = sanitize_text_field( $source );
    if ( '' === $source ) { return new WP_Error( 'employment_source', __( 'An employment-date source reference is required.', 'ghca-acd' ) ); }
    if ( $date === ( $old['date'] ?? null ) && $source === ( $old['source'] ?? null ) ) { return false; }
    if ( '1' !== ( $input['employment_verified'] ?? null ) ) {
      return new WP_Error( 'employment_unverified', __( 'Confirm that you checked the employment date against its source.', 'ghca-acd' ) );
    }
    if ( self::date_timestamp( $date ) > time() ) {
      return new WP_Error( 'employment_future', __( 'A future start date cannot be verified as an actual employment start.', 'ghca-acd' ) );
    }
    $record = array( 'date' => $date, 'source' => $source, 'verified_by' => get_current_user_id(), 'verified_at' => time() );
    if ( ! update_user_meta( $user_id, self::META_KEY, $record ) ) {
      return new WP_Error( 'employment_save_failed', __( 'Employment date could not be saved. No other record changes were applied.', 'ghca-acd' ) );
    }
    return true;
  }
}
