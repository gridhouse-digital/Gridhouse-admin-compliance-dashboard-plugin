<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

final class GHCA_Compliance_Program {
  const OPTION_NEW_HIRE_GROUPS = 'ghca_new_hire_group_ids';
  const OPTION_NEW_HIRE_DAYS   = 'ghca_new_hire_deadline_days';
  const DEFAULT_DEADLINE_DAYS    = 30;
  const AT_RISK_DAYS             = 7;

  /**
   * Per-request memo caches. get_user_status() is invoked several times per
   * employee while the dashboard aggregate is built, and the group→course
   * mapping is identical for every user in the same groups — recomputing
   * either per call multiplies LearnDash queries by the employee count.
   *
   * @var array<int,array<string,mixed>> */
  private static $user_status_cache = array();

  /** @var array<string,array<int>> */
  private static $group_course_ids_cache = array();

  /** Drop the per-request memo caches (call after mutating LearnDash data). */
  public static function reset_runtime_cache(): void {
    self::$user_status_cache      = array();
    self::$group_course_ids_cache = array();
  }

  public static function init(): void {
    add_filter( 'ghca_new_hire_group_ids', array( __CLASS__, 'filter_new_hire_group_ids' ) );
    add_filter( 'ghca_new_hire_deadline_days', array( __CLASS__, 'filter_deadline_days' ) );
  }

  /** @return array<int> */
  public static function filter_new_hire_group_ids( array $ids ): array {
    $saved = get_option( self::OPTION_NEW_HIRE_GROUPS, array() );
    if ( is_array( $saved ) && ! empty( $saved ) ) {
      return array_values( array_unique( array_map( 'intval', $saved ) ) );
    }

    return $ids;
  }

  public static function filter_deadline_days( int $days ): int {
    $saved = (int) get_option( self::OPTION_NEW_HIRE_DAYS, self::DEFAULT_DEADLINE_DAYS );
    if ( $saved > 0 ) {
      return $saved;
    }

    return $days > 0 ? $days : self::DEFAULT_DEADLINE_DAYS;
  }

  /** @return array<int> */
  public static function get_new_hire_group_ids(): array {
    return array_values(
      array_unique(
        array_map(
          'intval',
          (array) apply_filters( 'ghca_new_hire_group_ids', array() )
        )
      )
    );
  }

  public static function get_deadline_days(): int {
    return (int) apply_filters( 'ghca_new_hire_deadline_days', self::DEFAULT_DEADLINE_DAYS );
  }

  public static function is_configured(): bool {
    return ! empty( self::get_new_hire_group_ids() );
  }

  /** @return array<int> */
  public static function get_user_new_hire_group_ids( int $user_id ): array {
    $matched = array();

    foreach ( self::get_new_hire_group_ids() as $group_id ) {
      if ( function_exists( 'learndash_is_user_in_group' ) && learndash_is_user_in_group( $user_id, $group_id ) ) {
        $matched[] = (int) $group_id;
      }
    }

    return $matched;
  }

  /** @param array<int> $group_ids */
  public static function get_enrollment_timestamp( int $user_id, array $group_ids ): int {
    $candidates = array();

    $user = get_userdata( $user_id );
    if ( $user ) {
      $registered = strtotime( $user->user_registered );
      if ( $registered ) {
        $candidates[] = $registered;
      }
    }

    foreach ( $group_ids as $group_id ) {
      foreach ( array(
        'group_' . $group_id . '_access_from',
        'learndash_group_' . $group_id . '_enrolled_at',
      ) as $meta_key ) {
        $value = get_user_meta( $user_id, $meta_key, true );
        if ( is_numeric( $value ) && (int) $value > 0 ) {
          $candidates[] = (int) $value;
        }
      }
    }

    $candidates = array_values( array_filter( $candidates ) );
    return empty( $candidates ) ? 0 : (int) min( $candidates );
  }

  /** @param array<int> $group_ids @return array<int> */
  public static function get_group_course_ids( array $group_ids ): array {
    $cache_key = implode( ',', array_map( 'intval', $group_ids ) );
    if ( isset( self::$group_course_ids_cache[ $cache_key ] ) ) {
      return self::$group_course_ids_cache[ $cache_key ];
    }

    $course_ids = array();

    foreach ( $group_ids as $group_id ) {
      $ids = array();

      if ( function_exists( 'learndash_group_enrolled_courses' ) ) {
        $ids = learndash_group_enrolled_courses( $group_id );
      }
      if ( empty( $ids ) && function_exists( 'learndash_get_group_courses_list' ) ) {
        $ids = learndash_get_group_courses_list( $group_id );
      }
      if ( empty( $ids ) && function_exists( 'learndash_get_groups_courses_ids' ) ) {
        $ids = learndash_get_groups_courses_ids( $group_id );
      }

      if ( is_array( $ids ) ) {
        $course_ids = array_merge( $course_ids, array_map( 'intval', $ids ) );
      }
    }

    self::$group_course_ids_cache[ $cache_key ] = array_values( array_unique( array_filter( $course_ids ) ) );
    return self::$group_course_ids_cache[ $cache_key ];
  }

  /** @return array<int,array<string,mixed>> */
  public static function get_user_courses( int $user_id, array $group_ids ): array {
    $items = array();

    foreach ( self::get_group_course_ids( $group_ids ) as $course_id ) {
      $title     = self::normalize_course_title(
        html_entity_decode( (string) get_the_title( $course_id ), ENT_QUOTES, 'UTF-8' )
      );
      $status    = function_exists( 'learndash_course_status' ) ? (string) learndash_course_status( $course_id, $user_id, true ) : 'not_started';
      $progress  = function_exists( 'learndash_course_progress' ) ? (array) learndash_course_progress(
        array(
          'user_id'   => $user_id,
          'course_id' => $course_id,
          'array'     => true,
        )
      ) : array();
      $percent   = isset( $progress['percentage'] ) ? (int) $progress['percentage'] : 0;
      $completed = function_exists( 'learndash_course_completed' ) ? (bool) learndash_course_completed( $user_id, $course_id ) : false;
      if ( $completed ) {
        $percent = 100;
      }
      $completed_ts = self::get_course_completed_timestamp( $user_id, $course_id );

      $items[] = array(
        'id'                 => $course_id,
        'title'              => $title,
        'status'             => $completed ? 'completed' : $status,
        'status_label'       => self::course_status_label( $completed ? 'completed' : $status ),
        'completed'          => $completed,
        'completed_ts'       => $completed_ts,
        'progress'           => $percent,
        'progress_label'     => $percent . '%',
        'url'                => get_permalink( $course_id ) ?: home_url( '/my-courses/' ),
        'has_certificate'    => ! empty( get_post_meta( $course_id, '_ld_certificate', true ) ),
        'certificate_url'    => self::get_certificate_url( $user_id, $course_id ),
        'completed_recently' => $completed && $completed_ts > ( time() - ( 30 * DAY_IN_SECONDS ) ),
        'last_activity_ts'   => self::get_course_last_activity_timestamp( $user_id, $course_id, $completed_ts ),
      ) + GHCA_Course_Lifespans::decorate( $course_id, $completed, $completed_ts );
    }

    return $items;
  }

  /** @return array<string,mixed> */
  public static function get_user_status( int $user_id ): array {
    if ( isset( self::$user_status_cache[ $user_id ] ) ) {
      return self::$user_status_cache[ $user_id ];
    }
    return self::$user_status_cache[ $user_id ] = self::compute_user_status( $user_id );
  }

  /** @return array<string,mixed> */
  private static function compute_user_status( int $user_id ): array {
    $empty = array(
      'active'             => false,
      'configured'         => self::is_configured(),
      'complete'           => false,
      'overdue'            => false,
      'at_risk'            => false,
      'flagged'            => false,
      'group_ids'          => array(),
      'group_id'           => 0,
      'group_label'        => '',
      'enrollment_ts'      => 0,
      'deadline_ts'        => 0,
      'deadline_label'     => '',
      'days_remaining'     => null,
      'days_elapsed'       => 0,
      'deadline_days'      => self::get_deadline_days(),
      'courses'            => array(),
      'total_courses'      => 0,
      'completed_count'    => 0,
      'status_slug'        => '',
      'status_label'       => '',
    );

    if ( ! self::is_configured() ) {
      return $empty;
    }

    $group_ids = self::get_user_new_hire_group_ids( $user_id );
    if ( empty( $group_ids ) ) {
      return $empty;
    }

    $enrollment_ts = self::get_enrollment_timestamp( $user_id, $group_ids );
    if ( ! $enrollment_ts ) {
      return $empty;
    }

    $deadline_days  = self::get_deadline_days();
    $deadline_ts    = $enrollment_ts + ( $deadline_days * DAY_IN_SECONDS );
    $courses        = self::get_user_courses( $user_id, $group_ids );
    $completed      = 0;
    $started        = 0;

    foreach ( $courses as $course ) {
      if ( ! empty( $course['completed'] ) ) {
        ++$completed;
      } elseif ( 'in_progress' === $course['status'] ) {
        ++$started;
      }
    }

    $total         = count( $courses );
    $all_complete  = $total > 0 && $completed === $total;
    $now           = time();
    $days_elapsed  = (int) floor( max( 0, $now - $enrollment_ts ) / DAY_IN_SECONDS );
    $days_remaining = (int) max( 0, (int) ceil( ( $deadline_ts - $now ) / DAY_IN_SECONDS ) );
    $overdue       = ! $all_complete && $now > $deadline_ts;
    $at_risk       = ! $all_complete && ! $overdue && $days_remaining <= self::AT_RISK_DAYS;
    $flagged       = $overdue;

    if ( $all_complete ) {
      $status_slug  = 'new_hire_completed';
      $status_label = __( 'New Hire Complete', 'ghca-acd' );
    } elseif ( $overdue ) {
      $status_slug  = 'new_hire_overdue';
      $status_label = __( 'New Hire Overdue', 'ghca-acd' );
    } elseif ( $started > 0 || $completed > 0 ) {
      $status_slug  = 'new_hire_in_progress';
      $status_label = __( 'New Hire In Progress', 'ghca-acd' );
    } else {
      $status_slug  = 'new_hire_not_started';
      $status_label = __( 'New Hire Not Started', 'ghca-acd' );
    }

    return array(
      'active'             => true,
      'configured'         => true,
      'complete'           => $all_complete,
      'overdue'            => $overdue,
      'at_risk'            => $at_risk,
      'flagged'            => $flagged,
      'group_ids'          => $group_ids,
      'group_id'           => (int) $group_ids[0],
      'group_label'        => get_the_title( $group_ids[0] ),
      'enrollment_ts'      => $enrollment_ts,
      'deadline_ts'        => $deadline_ts,
      'deadline_label'     => wp_date( 'F j, Y', $deadline_ts ),
      'days_remaining'     => $all_complete ? 0 : $days_remaining,
      'days_elapsed'       => $days_elapsed,
      'deadline_days'      => $deadline_days,
      'courses'            => $courses,
      'total_courses'      => $total,
      'completed_count'    => $completed,
      'status_slug'        => $status_slug,
      'status_label'       => $status_label,
    );
  }

  public static function user_requires_new_hire_tracking( int $user_id ): bool {
    $status = self::get_user_status( $user_id );
    return ! empty( $status['active'] ) && empty( $status['complete'] );
  }

  private static function normalize_course_title( string $title ): string {
    return mb_strtoupper( trim( $title ), 'UTF-8' );
  }

  private static function course_status_label( string $status ): string {
    $map = array(
      'not_started' => __( 'Not Started', 'ghca-acd' ),
      'in_progress' => __( 'In Progress', 'ghca-acd' ),
      'completed'   => __( 'Completed', 'ghca-acd' ),
    );

    return $map[ $status ] ?? __( 'Unknown', 'ghca-acd' );
  }

  private static function get_certificate_url( int $user_id, int $course_id ): string {
    if ( function_exists( 'learndash_get_course_certificate_link' ) ) {
      $link = learndash_get_course_certificate_link( $course_id, $user_id );
      if ( is_string( $link ) && $link !== '' ) {
        return $link;
      }
    }

    $uo = get_user_meta( $user_id, '_uo-course-cert-' . $course_id, true );
    if ( is_array( $uo ) && ! empty( $uo ) ) {
      $first = reset( $uo );
      if ( is_string( $first ) && $first !== '' ) {
        return $first;
      }
    }

    return '';
  }

  private static function get_course_completed_timestamp( int $user_id, int $course_id ): int {
    $activity = get_user_meta( $user_id, 'course_completed_' . $course_id, true );
    return is_numeric( $activity ) ? (int) $activity : 0;
  }

  private static function get_course_last_activity_timestamp( int $user_id, int $course_id, int $completed_ts = 0 ): int {
    if ( $completed_ts > 0 ) {
      return $completed_ts;
    }

    $activity = get_user_meta( $user_id, 'course_' . $course_id . '_access_from', true );
    if ( is_numeric( $activity ) && (int) $activity > 0 ) {
      return (int) $activity;
    }

    return 0;
  }
}
