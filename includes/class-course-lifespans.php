<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Rolling expiration ("traffic light") policy for required courses.
 *
 * Pure decision functions live here so they can be reasoned about and tested
 * in isolation, mirroring how GHCA_Compliance_Program isolates new-hire logic.
 */
final class GHCA_Course_Lifespans {
  const OPTION_LIFESPANS     = 'ghca_acd_course_lifespans';
  const OPTION_WARNING_DAYS  = 'ghca_acd_warning_days';
  const DEFAULT_WARNING_DAYS = 90;

  /**
   * Classify one course's compliance state.
   *
   * @return array{state:string,expiration_ts:int}
   *   state ∈ incomplete | current (🟢) | expiring_soon (🟡) | expired (🔴)
   */
  public static function evaluate( bool $completed, int $completed_ts, int $lifespan_days, int $warning_days, int $now ): array {
    if ( ! $completed ) {
      return array( 'state' => 'incomplete', 'expiration_ts' => 0 );
    }

    // Complete-once: no lifespan configured, or no anchor timestamp to count
    // from (legacy/imported completions stay green until HR sets a date).
    if ( $lifespan_days <= 0 || $completed_ts <= 0 ) {
      return array( 'state' => 'current', 'expiration_ts' => 0 );
    }

    $expiration_ts = $completed_ts + ( $lifespan_days * DAY_IN_SECONDS );

    if ( $now >= $expiration_ts ) {
      $state = 'expired';
    } elseif ( $now >= ( $expiration_ts - ( $warning_days * DAY_IN_SECONDS ) ) ) {
      $state = 'expiring_soon';
    } else {
      $state = 'current';
    }

    return array( 'state' => $state, 'expiration_ts' => $expiration_ts );
  }

  /**
   * Worst-wins rollup across a user's required course states.
   *
   * @param array<int,string> $states
   */
  public static function rollup( array $states ): string {
    if ( in_array( 'expired', $states, true ) ) {
      return 'expired';
    }
    if ( in_array( 'expiring_soon', $states, true ) ) {
      return 'expiring_soon';
    }
    return 'current';
  }

  /** @return array<int,int> course_id => lifespan days */
  public static function get_lifespan_map(): array {
    $raw = get_option( self::OPTION_LIFESPANS, array() );
    return self::sanitize_lifespan_map( $raw );
  }

  public static function get_lifespan_days( int $course_id ): int {
    $map = self::get_lifespan_map();
    return isset( $map[ $course_id ] ) ? (int) $map[ $course_id ] : 0;
  }

  public static function get_warning_days(): int {
    $days = (int) get_option( self::OPTION_WARNING_DAYS, self::DEFAULT_WARNING_DAYS );
    return max( 7, min( 365, $days ) );
  }

  /**
   * Normalize the saved map to {positive int course_id: 1..3650 days}.
   * Pure: stray course IDs are harmless (a course not in a user's enrollment
   * is never evaluated), so no WP lookups happen here.
   *
   * @param mixed $value
   * @return array<int,int>
   */
  public static function sanitize_lifespan_map( $value ): array {
    $out = array();
    if ( ! is_array( $value ) ) {
      return $out;
    }
    foreach ( $value as $course_id => $days ) {
      $course_id = (int) $course_id;
      $days      = (int) $days;
      if ( $course_id <= 0 || $days <= 0 ) {
        continue;
      }
      $out[ $course_id ] = max( 1, min( 3650, $days ) );
    }
    return $out;
  }
}
