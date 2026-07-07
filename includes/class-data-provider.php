<?php
/**
 * Data engine for the compliance dashboard.
 *
 * Owns employee/course aggregation, status + priority logic, filter parsing,
 * caching, and shared formatting helpers. Extracted from
 * GHCA_Admin_Compliance_Dashboard (Phase 6 core file refactor).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_Data_Provider {

  /** @var array<string,mixed>|null */
  private static $aggregate = null;

  /** @var array<string,string> */
  private static $page_url_cache = array();

  /** @return array<int,array<string,mixed>> */
  public static function get_employees_for_current_view(): array {
    return self::get_aggregate()['employees'];
  }

  /** @return array<int> */
  private static function get_compliance_group_ids(): array {
    return GHCA_ACD_Scoping::get_visible_group_ids();
  }

  /** @return array<int> */
  private static function get_tracked_group_ids(): array {
    return array_values(
      array_unique(
        array_merge(
          self::get_compliance_group_ids(),
          GHCA_Compliance_Program::get_new_hire_group_ids()
        )
      )
    );
  }

  private static function get_at_risk_days(): int {
    return GHCA_ACD_Settings::get_at_risk_days();
  }

  private static function get_cache_key(): string {
    // Date stamp (site timezone) makes the aggregate self-invalidate at local
    // midnight, so a course flipping 🟡→🔴 overnight recomputes with no DB write.
    return 'ghca_acd_agg_' . GHCA_Admin_Compliance_Dashboard::VERSION . '_' . get_current_user_id() . '_' . wp_date( 'Ymd' );
  }

  public static function bust_cache(): void {
    delete_transient( self::get_cache_key() );
    self::$aggregate = null;
    // The compliance-program memo caches derive from the same LearnDash data;
    // an AJAX record edit must not serve a stale status later in the request.
    GHCA_Compliance_Program::reset_runtime_cache();
  }

  /** @return array<int> */
  public static function get_employee_user_ids(): array {
    $ids = array();

    foreach ( self::get_tracked_group_ids() as $group_id ) {
      if ( ! function_exists( 'learndash_get_groups_user_ids' ) ) {
        continue;
      }
      $group_users = learndash_get_groups_user_ids( $group_id );
      if ( ! is_array( $group_users ) ) {
        continue;
      }
      foreach ( $group_users as $uid ) {
        $uid = (int) $uid;
        if ( $uid <= 0 ) {
          continue;
        }
        $ids[] = $uid;
      }
    }

    // Also include any WP users not enrolled in any tracked group, if allowed.
    if ( GHCA_ACD_Roles::user_has_unrestricted_view() ) {
      $all_wp_users = get_users( array(
        'fields'  => 'ID',
        'orderby' => 'registered',
        'order'   => 'ASC',
      ) );
      // Bulk-prime the object cache so the role/super-admin checks below are
      // memory reads instead of one users + one usermeta query per user.
      cache_users( array_map( 'intval', $all_wp_users ) );
      foreach ( $all_wp_users as $wp_uid ) {
        $wp_uid = (int) $wp_uid;
        if ( $wp_uid <= 0 || in_array( $wp_uid, $ids, true ) ) {
          continue;
        }
        $user = get_userdata( $wp_uid );
        if ( ! $user ) {
          continue;
        }
        // Skip super admins on multisite or users with no real role.
        if ( empty( $user->roles ) || is_super_admin( $wp_uid ) ) {
          continue;
        }
        $ids[] = $wp_uid;
      }
    }

    return array_values( array_unique( $ids ) );
  }

  /** @return array<string,mixed> */
  public static function get_aggregate(): array {
    if ( null !== self::$aggregate ) {
      return self::$aggregate;
    }

    $ttl = GHCA_ACD_Settings::get_cache_ttl();
    if ( $ttl > 0 ) {
      $cached = get_transient( self::get_cache_key() );
      if ( is_array( $cached ) ) {
        self::$aggregate = $cached;
        return self::$aggregate;
      }
    }

    $employees         = self::build_employee_records();
    $total             = count( $employees );
    $completed         = 0;
    $in_progress       = 0;
    $overdue           = 0;
    $certificates      = 0;
    $cert_available    = 0;
    $cert_missing      = 0;
    $eligible          = 0;
    $recent_completed  = 0;
    $recent_items      = array();
    $next_due_ts       = PHP_INT_MAX;
    $expiring          = 0;

    foreach ( $employees as $employee ) {
      if ( 'completed' === $employee['status_slug'] || 'new_hire_completed' === $employee['status_slug'] ) {
        ++$completed;
      } elseif ( 'expiring_soon' === $employee['status_slug'] ) {
        ++$expiring;
      } elseif ( in_array( $employee['status_slug'], array( 'overdue', 'new_hire_overdue', 'expired' ), true ) ) {
        ++$overdue;
      } elseif ( in_array( $employee['status_slug'], array( 'in_progress', 'new_hire_in_progress', 'new_hire_not_started' ), true ) ) {
        ++$in_progress;
      }

      if ( ! empty( $employee['certificate_url'] ) ) {
        ++$certificates;
      }

      foreach ( $employee['courses'] as $course ) {
        if ( ! empty( $course['has_certificate'] ) ) {
          ++$cert_available;
          if ( ! empty( $course['completed'] ) && empty( $course['certificate_url'] ) ) {
            ++$cert_missing;
            ++$eligible;
          }
        }
        if ( ! empty( $course['completed'] ) && ! empty( $course['completed_recently'] ) ) {
          ++$recent_completed;
          $recent_items[] = array(
            'name'   => $employee['name'],
            'course' => $course['title'],
            'ts'     => (int) ( $course['last_activity_ts'] ?? 0 ),
          );
        }
      }

      if ( ! empty( $employee['due_timestamp'] ) && $employee['due_timestamp'] < $next_due_ts && 'completed' !== $employee['status_slug'] ) {
        $next_due_ts = (int) $employee['due_timestamp'];
      }
    }

    // Yellow (expiring soon) employees are still compliant for now.
    $rate = $total > 0 ? (int) round( ( ( $completed + $expiring ) / $total ) * 100 ) : 0;

    usort(
      $recent_items,
      static function ( array $a, array $b ): int {
        return (int) $b['ts'] <=> (int) $a['ts'];
      }
    );

    self::$aggregate = array(
      'employees'                  => $employees,
      'total_employees'            => $total,
      'compliance_rate'            => $rate,
      'compliance_rate_label'        => $total ? $rate . '% compliant' : __( 'No employees assigned', 'ghca-acd' ),
      'completed_employees'          => $completed,
      'completed_employees_label'    => sprintf( _n( '%d completed', '%d completed', $completed, 'ghca-acd' ), $completed ),
      'in_progress_employees'      => $in_progress,
      'in_progress_employees_label'  => sprintf( _n( '%d in progress', '%d in progress', $in_progress, 'ghca-acd' ), $in_progress ),
      'overdue_employees'            => $overdue,
      'overdue_employees_label'      => sprintf( _n( '%d overdue', '%d overdue', $overdue, 'ghca-acd' ), $overdue ),
      'expiring_soon_employees'      => $expiring,
      'expiring_soon_employees_label' => sprintf( _n( '%d expiring soon', '%d expiring soon', $expiring, 'ghca-acd' ), $expiring ),
      'certificates_issued'          => $certificates,
      'certificates_issued_label'    => sprintf( _n( '%d certificate', '%d certificates', $certificates, 'ghca-acd' ), $certificates ),
      'upcoming_due_date_label'      => PHP_INT_MAX === $next_due_ts ? self::format_due_date( get_option( GHCA_Admin_Compliance_Dashboard::OPTION_DUE_DATE, '2026-07-31' ) ) : wp_date( 'F j, Y', $next_due_ts ),
      'certificates_available'       => $cert_available,
      'certificates_missing'         => $cert_missing,
      'recently_completed'           => $recent_completed,
      'eligible_for_certificate'     => $eligible,
      'recent_completions'           => array_slice( $recent_items, 0, 8 ),
    );

    if ( $ttl > 0 ) {
      set_transient( self::get_cache_key(), self::$aggregate, $ttl );
    }

    return self::$aggregate;
  }

  /** @return array<int,array<string,mixed>> */
  private static function build_employee_records(): array {
    if ( ! function_exists( 'learndash_user_get_enrolled_courses' ) ) {
      return array();
    }

    $user_ids = self::get_employee_user_ids();
    if ( empty( $user_ids ) ) {
      return array();
    }

    // Eager-load every user object and ALL user meta into the object cache in
    // two bulk queries. LearnDash keeps course completions
    // ('course_completed_{ID}'), certificates and group enrollment stamps in
    // user meta, so every get_userdata()/get_user_meta() in the loop below
    // reads from memory instead of issuing its own query per user.
    cache_users( $user_ids );

    // Same idea for FluentCRM: resolve every employee's subscriber id in one
    // query instead of one per row inside build_employee_actions_html().
    // Emails are memory reads here thanks to cache_users() above.
    $emails = array();
    foreach ( $user_ids as $user_id ) {
      $user = get_userdata( $user_id );
      if ( $user && $user->user_email ) {
        $emails[] = $user->user_email;
      }
    }
    GHCA_ACD_FluentCRM::prime_contact_cache( $emails );

    $records = array();
    foreach ( $user_ids as $user_id ) {
      $records[] = self::build_employee_record( $user_id );
    }

    usort(
      $records,
      static function ( array $a, array $b ): int {
        return strcasecmp( $a['name'], $b['name'] );
      }
    );

    return $records;
  }

  /** @return array<string,mixed> */
  private static function build_employee_record( int $user_id ): array {
    $user     = get_userdata( $user_id );
    $new_hire = GHCA_Compliance_Program::get_user_status( $user_id );

    if ( ! empty( $new_hire['active'] ) && empty( $new_hire['complete'] ) ) {
      $courses         = $new_hire['courses'];
      $completed_count   = (int) $new_hire['completed_count'];
      $total             = (int) $new_hire['total_courses'];
      $all_complete      = ! empty( $new_hire['complete'] );
      $due_ts            = (int) $new_hire['deadline_ts'];
      $due_date_label    = (string) $new_hire['deadline_label'];
      $status_slug       = (string) $new_hire['status_slug'];
      $status_label      = (string) $new_hire['status_label'];
      $started_count     = 0;

      foreach ( $courses as $course ) {
        if ( empty( $course['completed'] ) && 'in_progress' === $course['status'] ) {
          ++$started_count;
        }
      }

      $certificate_url = '';
      foreach ( $courses as $course ) {
        if ( ! empty( $course['certificate_url'] ) ) {
          $certificate_url = $course['certificate_url'];
          break;
        }
      }

      $progress_pct = $total > 0 ? (int) round( ( $completed_count / $total ) * 100 ) : 0;

      // Rolling expiration overrides onboarding. A previously-completed required
      // course that has lapsed makes the employee Overdue even mid-onboarding
      // (precedence: expired > new-hire status). Evaluate the FULL required course
      // set, not just new-hire group courses, so a lapsed cert outside the
      // onboarding group is still caught. Expiring-soon does NOT override here —
      // an incomplete new hire is not yet compliant.
      $expiry_states = array();
      foreach ( self::get_user_courses( $user_id ) as $c ) {
        $expiry_states[] = (string) ( $c['compliance_state'] ?? 'incomplete' );
      }
      $expiry_state = GHCA_Course_Lifespans::rollup( $expiry_states );
      if ( 'expired' === $expiry_state ) {
        $status_slug  = 'expired';
        $status_label = __( 'Expired', 'ghca-acd' );
      }

      return array(
        'user_id'              => $user_id,
        'name'                 => self::get_user_full_name( $user_id, $user ),
        'email'                => $user ? $user->user_email : '',
        'group'                => (string) $new_hire['group_label'],
        'group_id'             => (int) $new_hire['group_id'],
        'courses'              => $courses,
        'total_courses'        => $total,
        'completed_count'      => $completed_count,
        'progress_pct'         => $progress_pct,
        'progress_label'       => $progress_pct . '%',
        'required_label'       => $total ? (string) $total : '0',
        'completed_label'      => $total ? sprintf( '%1$d / %2$d', $completed_count, $total ) : '0',
        'due_timestamp'        => $due_ts,
        'due_date_label'       => $due_date_label,
        'last_activity_label'  => self::get_last_activity_label( $user_id, $courses ),
        'status_slug'          => $status_slug,
        'status_label'         => $status_label,
        'expiry_state'         => $expiry_state,
        'new_hire'             => $new_hire,
        'certificate_url'      => $certificate_url,
        'certificate_label'    => $certificate_url ? __( 'Available', 'ghca-acd' ) : ( $all_complete ? __( 'Check certificates', 'ghca-acd' ) : __( 'Pending', 'ghca-acd' ) ),
        'actions_html'         => GHCA_ACD_Shortcodes::build_employee_actions_html( $user_id, $user ? $user->user_email : '' ),
      );
    }

    $courses = self::get_user_courses( $user_id );
    $cycle   = self::get_compliance_cycle( $user_id );

    $completed_count = 0;
    $started_count   = 0;
    $certificate_url = '';

    foreach ( $courses as $course ) {
      if ( ! empty( $course['completed'] ) ) {
        ++$completed_count;
        if ( ! empty( $course['certificate_url'] ) ) {
          $certificate_url = $course['certificate_url'];
        }
      } elseif ( 'in_progress' === $course['status'] ) {
        ++$started_count;
      }
    }

    $total        = count( $courses );
    $all_complete = $total > 0 && $completed_count === $total;
    $due_ts       = (int) ( $cycle['due_timestamp'] ?? 0 );
    $is_overdue   = ! $all_complete && $due_ts > 0 && time() > $due_ts;

    // Roll up per-course traffic-light state (completed courses only react here).
    $course_states = array();
    foreach ( $courses as $c ) {
      $course_states[] = (string) ( $c['compliance_state'] ?? 'incomplete' );
    }
    $expiry_state = GHCA_Course_Lifespans::rollup( $course_states );

    if ( 'expired' === $expiry_state ) {
      // Finished but past its rolling lifespan → 🔴 Expired. Distinct row label
      // from incomplete "Overdue", but both roll into the Overdue KPI bucket.
      $status_slug  = 'expired';
      $status_label = __( 'Expired', 'ghca-acd' );
    } elseif ( $all_complete ) {
      if ( 'expiring_soon' === $expiry_state ) {
        $status_slug  = 'expiring_soon';
        $status_label = __( 'Expiring Soon', 'ghca-acd' );
      } else {
        $status_slug  = 'completed';
        $status_label = __( 'Completed', 'ghca-acd' );
      }
    } elseif ( $is_overdue ) {
      $status_slug  = 'overdue';
      $status_label = __( 'Overdue', 'ghca-acd' );
    } elseif ( $started_count > 0 || $completed_count > 0 ) {
      $status_slug  = 'in_progress';
      $status_label = __( 'In Progress', 'ghca-acd' );
    } else {
      $status_slug  = 'not_started';
      $status_label = __( 'Not Started', 'ghca-acd' );
    }

    $progress_pct = $total > 0 ? (int) round( ( $completed_count / $total ) * 100 ) : 0;

    return array(
      'user_id'              => $user_id,
      'name'                 => self::get_user_full_name( $user_id, $user ),
      'email'                => $user ? $user->user_email : '',
      'group'                => self::get_user_group_label( $user_id ),
      'group_id'             => self::get_user_primary_group_id( $user_id ),
      'courses'              => $courses,
      'total_courses'        => $total,
      'completed_count'      => $completed_count,
      'progress_pct'         => $progress_pct,
      'progress_label'       => $progress_pct . '%',
      'required_label'       => $total ? (string) $total : '0',
      'completed_label'      => $total ? sprintf( '%1$d / %2$d', $completed_count, $total ) : '0',
      'due_timestamp'        => $due_ts,
      'due_date_label'       => $cycle['due_date_label'] ?: self::format_due_date( get_option( GHCA_Admin_Compliance_Dashboard::OPTION_DUE_DATE, '2026-07-31' ) ),
      'last_activity_label'  => self::get_last_activity_label( $user_id, $courses ),
      'status_slug'          => $status_slug,
      'status_label'         => $status_label,
      'expiry_state'         => $expiry_state,
      'new_hire'             => $new_hire,
      'certificate_url'      => $certificate_url,
      'certificate_label'    => $certificate_url ? __( 'Available', 'ghca-acd' ) : ( $all_complete ? __( 'Check certificates', 'ghca-acd' ) : __( 'Pending', 'ghca-acd' ) ),
      'actions_html'         => GHCA_ACD_Shortcodes::build_employee_actions_html( $user_id, $user ? $user->user_email : '' ),
    );
  }

  /** @return array<int,array<string,mixed>> */
  private static function get_user_courses( int $user_id ): array {
    $course_ids = array_map( 'intval', (array) learndash_user_get_enrolled_courses( $user_id ) );
    $items      = array();

    foreach ( array_values( array_unique( array_filter( $course_ids ) ) ) as $course_id ) {
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
      $cert      = self::get_certificate_url( $user_id, $course_id );
      $completed_ts = self::get_course_completed_timestamp( $user_id, $course_id );

      $items[] = array(
        'id'                 => $course_id,
        'title'              => $title,
        'status'             => $completed ? 'completed' : $status,
        'status_label'       => self::course_status_label( $completed ? 'completed' : $status ),
        'completed'          => $completed,
        'completed_ts'       => $completed_ts, // Added for Edit Records
        'progress'           => $percent,
        'progress_label'     => $percent . '%',
        'url'                => get_permalink( $course_id ) ?: home_url( '/my-courses/' ),
        'has_certificate'    => self::course_has_certificate( $course_id ),
        'certificate_url'    => $cert,
        'completed_recently' => $completed && $completed_ts > ( time() - ( 30 * DAY_IN_SECONDS ) ),
        'last_activity_ts'   => self::get_course_last_activity_timestamp( $user_id, $course_id, $completed_ts ),
      ) + GHCA_Course_Lifespans::decorate( $course_id, $completed, $completed_ts );
    }

    return $items;
  }

  /** @return array<string,mixed> */
  public static function get_employee_record( int $user_id ): array {
    return self::build_employee_record( $user_id );
  }

  public static function format_activity_label( int $timestamp ): string {
    return self::format_timestamp_label( $timestamp );
  }

  public static function get_user_report_url( int $user_id ): string {
    $slug = apply_filters( 'ghca_user_report_page_slug', 'user-report' );
    return add_query_arg( 'user_id', $user_id, self::get_page_url( $slug, '/user-report/' ) );
  }

  public static function get_admin_dashboard_url(): string {
    $slug = apply_filters( 'ghca_admin_dashboard_page_slug', 'compliance-admin-dashboard' );
    return self::get_page_url( $slug, '/compliance-admin-dashboard/' );
  }

  /** @param array<string,mixed> $filters @return array<int,array<string,mixed>> */
  public static function get_priority_rows( array $filters = array() ): array {
    $rows = array();

    foreach ( self::get_aggregate()['employees'] as $employee ) {
      if ( ! empty( $employee['completed_count'] ) && (int) $employee['completed_count'] === (int) $employee['total_courses'] && (int) $employee['total_courses'] > 0 && 'expired' !== $employee['status_slug'] ) {
        continue;
      }

      $priority = self::get_employee_priority( $employee );
      if ( ! in_array( $priority, array( 'overdue', 'at_risk' ), true ) ) {
        continue;
      }

      $rows[] = array(
        'priority'            => $priority,
        'user_id'             => (int) $employee['user_id'],
        'group_id'            => (int) $employee['group_id'],
        'name'                => $employee['name'],
        'email'               => $employee['email'],
        'group'               => $employee['group'],
        'progress_label'      => $employee['progress_label'],
        'progress_pct'        => $employee['progress_pct'],
        'next_course_label'   => self::get_next_course_label( $employee['courses'] ),
        'due_date_label'      => $employee['due_date_label'],
        'status_slug'         => $employee['status_slug'],
        'status_label'        => $employee['status_label'],
        'last_activity_label' => $employee['last_activity_label'],
        'actions_html'        => GHCA_ACD_Shortcodes::build_priority_actions_html( (int) $employee['user_id'], $employee['email'] ),
      );
    }

    $orderby = $filters['orderby'] ?? '';
    $order   = $filters['order'] ?? 'asc';

    if ( $orderby && in_array( $orderby, GHCA_Admin_Compliance_Dashboard::SORTABLE_COLUMNS, true ) ) {
      usort(
        $rows,
        static function ( array $a, array $b ) use ( $orderby, $order ): int {
          $val_a = $a[ $orderby ] ?? '';
          $val_b = $b[ $orderby ] ?? '';

          if ( $val_a === $val_b ) {
            return 0;
          }

          $cmp = 0;
          if ( is_numeric( $val_a ) && is_numeric( $val_b ) ) {
             $cmp = (float) $val_a < (float) $val_b ? -1 : 1;
          } else {
             $cmp = strcasecmp( (string) $val_a, (string) $val_b );
          }

          return $order === 'desc' ? -$cmp : $cmp;
        }
      );
    } else {
      usort(
        $rows,
        static function ( array $a, array $b ): int {
          if ( $a['priority'] !== $b['priority'] ) {
            return 'overdue' === $a['priority'] ? -1 : 1;
          }
          return strcasecmp( $a['name'], $b['name'] );
        }
      );
    }

    if ( ! empty( $filters['group'] ) ) {
      $group_id = (int) $filters['group'];
      $rows     = array_values(
        array_filter(
          $rows,
          static function ( array $row ) use ( $group_id ): bool {
            return (int) $row['group_id'] === $group_id;
          }
        )
      );
    }

    if ( ! empty( $filters['priority'] ) && in_array( $filters['priority'], array( 'overdue', 'at_risk' ), true ) ) {
      $priority_filter = (string) $filters['priority'];
      $rows            = array_values(
        array_filter(
          $rows,
          static function ( array $row ) use ( $priority_filter ): bool {
            return $row['priority'] === $priority_filter;
          }
        )
      );
    }

    if ( ! empty( $filters['search'] ) ) {
      $search = (string) $filters['search'];
      $rows   = array_values(
        array_filter(
          $rows,
          static function ( array $row ) use ( $search ): bool {
            return GHCA_ACD_Table_UI::matches_search( $search, array( $row['name'], $row['email'], $row['group'] ) );
          }
        )
      );
    }

    return $rows;
  }

  /** @param array<string,mixed> $employee */
  private static function get_employee_priority( array $employee ): string {
    if ( ! empty( $employee['new_hire']['overdue'] ) ) {
      return 'overdue';
    }
    if ( ! empty( $employee['new_hire']['at_risk'] ) ) {
      return 'at_risk';
    }

    $due_ts = (int) ( $employee['due_timestamp'] ?? 0 );
    if ( $due_ts > 0 && time() > $due_ts ) {
      return 'overdue';
    }
    if ( $due_ts > 0 && $due_ts <= ( time() + ( self::get_at_risk_days() * DAY_IN_SECONDS ) ) ) {
      return 'at_risk';
    }
    if ( in_array( $employee['status_slug'], array( 'overdue', 'new_hire_overdue', 'expired' ), true ) ) {
      return 'overdue';
    }
    return '';
  }

  /** @param array<int,array<string,mixed>> $courses */
  private static function get_next_course_label( array $courses ): string {
    $in_progress = null;
    $not_started = null;

    foreach ( $courses as $course ) {
      if ( ! empty( $course['completed'] ) ) {
        continue;
      }
      if ( 'in_progress' === $course['status'] && null === $in_progress ) {
        $in_progress = $course;
      } elseif ( null === $not_started ) {
        $not_started = $course;
      }
    }

    $next = $in_progress ?: $not_started;
    return $next ? (string) $next['title'] : __( 'None pending', 'ghca-acd' );
  }

  /** @param array<string,mixed> $filters @return array<int,array<string,mixed>> */
  public static function get_course_overview_rows( array $filters = array() ): array {
    $rows = array();
    $map  = array();

    foreach ( self::get_aggregate()['employees'] as $employee ) {
      foreach ( $employee['courses'] as $course ) {
        $key = $course['id'] . ':' . $employee['group_id'];
        if ( ! isset( $map[ $key ] ) ) {
          $map[ $key ] = array(
            'course'      => $course['title'],
            'group'       => $employee['group'],
            'group_id'    => (int) $employee['group_id'],
            'required'    => 0,
            'completed'   => 0,
            'in_progress' => 0,
            'not_started' => 0,
            'certificate' => ! empty( $course['has_certificate'] ),
          );
        }
        ++$map[ $key ]['required'];
        if ( ! empty( $course['completed'] ) ) {
          ++$map[ $key ]['completed'];
        } elseif ( 'in_progress' === $course['status'] ) {
          ++$map[ $key ]['in_progress'];
        } else {
          ++$map[ $key ]['not_started'];
        }
      }
    }

    foreach ( $map as $row ) {
      $rate                     = $row['required'] > 0 ? (int) round( ( $row['completed'] / $row['required'] ) * 100 ) : 0;
      $row['rate']              = $rate;
      $row['rate_label']        = $rate . '%';
      $row['certificate_label'] = $row['certificate'] ? __( 'Yes', 'ghca-acd' ) : __( 'No', 'ghca-acd' );
      $rows[]                   = $row;
    }

    usort(
      $rows,
      static function ( array $a, array $b ): int {
        return strcasecmp( $a['course'], $b['course'] );
      }
    );

    if ( ! empty( $filters['group'] ) ) {
      $group_id = (int) $filters['group'];
      $rows     = array_values(
        array_filter(
          $rows,
          static function ( array $row ) use ( $group_id ): bool {
            return (int) $row['group_id'] === $group_id;
          }
        )
      );
    }

    if ( ! empty( $filters['certificate'] ) ) {
      $want_cert = 'yes' === $filters['certificate'];
      $rows      = array_values(
        array_filter(
          $rows,
          static function ( array $row ) use ( $want_cert ): bool {
            return (bool) $row['certificate'] === $want_cert;
          }
        )
      );
    }

    if ( ! empty( $filters['search'] ) ) {
      $search = (string) $filters['search'];
      $rows   = array_values(
        array_filter(
          $rows,
          static function ( array $row ) use ( $search ): bool {
            return GHCA_ACD_Table_UI::matches_search( $search, array( $row['course'], $row['group'] ) );
          }
        )
      );
    }

    return $rows;
  }

  private static function get_request_value( string $key, string $default = '' ): string {
    if ( isset( $_POST[ $key ] ) ) {
      return sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) );
    }
    if ( isset( $_GET[ $key ] ) ) {
      return sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) );
    }
    return $default;
  }

  private static function get_request_flag( string $key ): bool {
    if ( isset( $_POST[ $key ] ) ) {
      return ! empty( $_POST[ $key ] );
    }
    if ( isset( $_GET[ $key ] ) ) {
      return ! empty( $_GET[ $key ] );
    }
    return false;
  }

  /** @return array<string,mixed> */
  public static function get_employee_filters(): array {
    return array(
      'group'        => self::get_request_value( 'ghca_group' ),
      'course'       => self::get_request_value( 'ghca_course' ),
      'status'       => self::get_request_value( 'ghca_status' ),
      'overdue_only' => self::get_request_flag( 'ghca_overdue' ),
      'search'       => self::get_request_value( 'ghca_emp_search' ),
      'page'         => GHCA_ACD_Table_UI::normalize_page( (int) self::get_request_value( 'ghca_emp_page', '1' ) ),
      'per_page'     => GHCA_ACD_Table_UI::normalize_per_page( (int) self::get_request_value( 'ghca_emp_per', '15' ) ),
      'orderby'      => self::sanitize_orderby( self::get_request_value( 'ghca_orderby' ) ),
      'order'        => in_array( self::get_request_value( 'ghca_order', 'asc' ), array( 'asc', 'desc' ), true ) ? self::get_request_value( 'ghca_order', 'asc' ) : 'asc',
    );
  }

  /** @return array<string,mixed> */
  public static function get_priority_filters(): array {
    $priority = self::get_request_value( 'ghca_pri_type' );
    if ( ! in_array( $priority, array( '', 'overdue', 'at_risk' ), true ) ) {
      $priority = '';
    }

    return array(
      'group'    => self::get_request_value( 'ghca_pri_group' ),
      'priority' => $priority,
      'search'   => self::get_request_value( 'ghca_pri_search' ),
      'page'     => GHCA_ACD_Table_UI::normalize_page( (int) self::get_request_value( 'ghca_pri_page', '1' ) ),
      'per_page' => GHCA_ACD_Table_UI::normalize_per_page( (int) self::get_request_value( 'ghca_pri_per', '15' ) ),
      'orderby'  => self::sanitize_orderby( self::get_request_value( 'ghca_orderby' ) ),
      'order'    => in_array( self::get_request_value( 'ghca_order', 'asc' ), array( 'asc', 'desc' ), true ) ? self::get_request_value( 'ghca_order', 'asc' ) : 'asc',
    );
  }

  /** @return array<string,mixed> */
  public static function get_course_filters(): array {
    $certificate = self::get_request_value( 'ghca_crs_cert' );
    if ( ! in_array( $certificate, array( '', 'yes', 'no' ), true ) ) {
      $certificate = '';
    }

    return array(
      'group'       => self::get_request_value( 'ghca_crs_group' ),
      'search'      => self::get_request_value( 'ghca_crs_search' ),
      'certificate' => $certificate,
      'page'        => GHCA_ACD_Table_UI::normalize_page( (int) self::get_request_value( 'ghca_crs_page', '1' ) ),
      'per_page'    => GHCA_ACD_Table_UI::normalize_per_page( (int) self::get_request_value( 'ghca_crs_per', '15' ) ),
    );
  }

  /** @param array<string,mixed> $filters @return array<int,array<string,mixed>> */
  public static function get_employee_table_rows( array $filters ): array {
    $rows = self::get_aggregate()['employees'];

    if ( $filters['group'] !== '' ) {
      $group_id = (int) $filters['group'];
      $rows     = array_values(
        array_filter(
          $rows,
          static function ( array $row ) use ( $group_id ): bool {
            return (int) $row['group_id'] === $group_id;
          }
        )
      );
    }

    if ( $filters['status'] !== '' ) {
      $rows = array_values(
        array_filter(
          $rows,
          static function ( array $row ) use ( $filters ): bool {
            $status = $row['status_slug'];
            if ( $filters['status'] === 'completed' ) {
              return in_array( $status, array( 'completed', 'new_hire_completed' ), true );
            }
            if ( $filters['status'] === 'expiring_soon' ) {
              return $status === 'expiring_soon';
            }
            if ( $filters['status'] === 'expired' ) {
              return $status === 'expired';
            }
            if ( $filters['status'] === 'overdue' ) {
              // "Everyone lapsed": never-finished overdue + expired re-certs.
              return in_array( $status, array( 'overdue', 'new_hire_overdue', 'expired' ), true );
            }
            if ( $filters['status'] === 'in_progress' ) {
              return in_array( $status, array( 'in_progress', 'new_hire_in_progress', 'new_hire_not_started' ), true );
            }
            if ( $filters['status'] === 'not_started' ) {
              return $status === 'not_started';
            }
            return $status === $filters['status'];
          }
        )
      );
    }

    if ( $filters['course'] !== '' ) {
      $course_id = (int) $filters['course'];
      $rows      = array_values(
        array_filter(
          $rows,
          static function ( array $row ) use ( $course_id ): bool {
            foreach ( $row['courses'] as $course ) {
              if ( (int) $course['id'] === $course_id ) {
                return true;
              }
            }
            return false;
          }
        )
      );
    }

    if ( $filters['overdue_only'] ) {
      $rows = array_values(
        array_filter(
          $rows,
          static function ( array $row ): bool {
            return in_array( $row['status_slug'], array( 'overdue', 'new_hire_overdue', 'expired' ), true );
          }
        )
      );
    }

    if ( ! empty( $filters['search'] ) ) {
      $search = (string) $filters['search'];
      $rows   = array_values(
        array_filter(
          $rows,
          static function ( array $row ) use ( $search ): bool {
            return GHCA_ACD_Table_UI::matches_search( $search, array( $row['name'], $row['email'], $row['group'] ) );
          }
        )
      );
    }

    $orderby = $filters['orderby'] ?? '';
    $order   = $filters['order'] ?? 'asc';

    if ( $orderby && in_array( $orderby, GHCA_Admin_Compliance_Dashboard::SORTABLE_COLUMNS, true ) ) {
      usort(
        $rows,
        static function ( array $a, array $b ) use ( $orderby, $order ): int {
          $val_a = $a[ $orderby ] ?? '';
          $val_b = $b[ $orderby ] ?? '';

          if ( $val_a === $val_b ) {
            return 0;
          }

          $cmp = 0;
          if ( is_numeric( $val_a ) && is_numeric( $val_b ) ) {
             $cmp = (float) $val_a < (float) $val_b ? -1 : 1;
          } else {
             $cmp = strcasecmp( (string) $val_a, (string) $val_b );
          }

          return $order === 'desc' ? -$cmp : $cmp;
        }
      );
    }

    return $rows;
  }

  /** @return array<int,string> */
  public static function get_group_options(): array {
    $options = array();
    foreach ( self::get_tracked_group_ids() as $group_id ) {
      $options[ $group_id ] = get_the_title( $group_id );
    }
    return $options;
  }

  /** @return array<int,string> */
  public static function get_course_options(): array {
    $options = array();
    foreach ( self::get_aggregate()['employees'] as $employee ) {
      foreach ( $employee['courses'] as $course ) {
        $options[ (int) $course['id'] ] = $course['title'];
      }
    }
    asort( $options );
    return $options;
  }

  /** @return array<string,string> */
  public static function get_status_options(): array {
    return array(
      'completed'     => __( 'Completed', 'ghca-acd' ),
      'expiring_soon' => __( 'Expiring Soon', 'ghca-acd' ),
      'in_progress'   => __( 'In Progress', 'ghca-acd' ),
      'not_started'   => __( 'Not Started', 'ghca-acd' ),
      'overdue'       => __( 'Overdue (incl. expired)', 'ghca-acd' ),
      'expired'       => __( 'Expired', 'ghca-acd' ),
    );
  }

  /** @param array<int,array<string,mixed>> $employees */
  public static function count_unique_tracked_courses( array $employees ): int {
    $course_ids = array();
    foreach ( $employees as $employee ) {
      foreach ( $employee['courses'] ?? array() as $course ) {
        $course_ids[ (int) ( $course['id'] ?? 0 ) ] = true;
      }
    }
    unset( $course_ids[0] );
    return count( $course_ids );
  }

  public static function get_user_full_name( int $user_id, ?WP_User $user = null ): string {
    if ( null === $user ) {
      $user = get_userdata( $user_id );
    }
    if ( ! $user ) {
      return __( 'Unknown user', 'ghca-acd' );
    }

    $first = trim( (string) get_user_meta( $user_id, 'first_name', true ) );
    $last  = trim( (string) get_user_meta( $user_id, 'last_name', true ) );
    $full  = trim( $first . ' ' . $last );

    if ( '' !== $full ) {
      return $full;
    }

    if ( '' !== trim( (string) $user->display_name ) ) {
      return $user->display_name;
    }

    return $user->user_login;
  }

  private static function get_user_group_label( int $user_id ): string {
    $group_id = self::get_user_primary_group_id( $user_id );
    return $group_id ? (string) get_the_title( $group_id ) : __( 'Unassigned', 'ghca-acd' );
  }

  private static function get_user_primary_group_id( int $user_id ): int {
    if ( GHCA_Compliance_Program::user_requires_new_hire_tracking( $user_id ) ) {
      $status = GHCA_Compliance_Program::get_user_status( $user_id );
      if ( ! empty( $status['group_id'] ) ) {
        return (int) $status['group_id'];
      }
    }

    foreach ( self::get_compliance_group_ids() as $group_id ) {
      if ( function_exists( 'learndash_is_user_in_group' ) && learndash_is_user_in_group( $user_id, $group_id ) ) {
        return (int) $group_id;
      }
    }
    return 0;
  }

  private static function course_has_certificate( int $course_id ): bool {
    return ! empty( get_post_meta( $course_id, '_ld_certificate', true ) );
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

    if ( function_exists( 'learndash_user_get_course_progress' ) ) {
      $progress = learndash_user_get_course_progress( $user_id, $course_id );
      if ( is_array( $progress ) && ! empty( $progress['last_activity'] ) && is_numeric( $progress['last_activity'] ) ) {
        return (int) $progress['last_activity'];
      }
    }

    return 0;
  }

  /** @param array<int,array<string,mixed>> $courses */
  private static function get_last_activity_label( int $user_id, array $courses ): string {
    $latest = 0;
    foreach ( $courses as $course ) {
      $ts = (int) ( $course['last_activity_ts'] ?? 0 );
      if ( $ts > $latest ) {
        $latest = $ts;
      }
    }
    return self::format_timestamp_label( $latest );
  }

  private static function format_timestamp_label( int $timestamp ): string {
    if ( $timestamp <= 0 ) {
      return __( 'No activity', 'ghca-acd' );
    }
    return wp_date( 'M j, Y', $timestamp );
  }

  private static function normalize_course_title( string $title ): string {
    return mb_strtoupper( trim( $title ), 'UTF-8' );
  }

  private static function course_status_label( string $status ): string {
    $map = array(
      'not_started' => __( 'Not Started', 'ghca-acd' ),
      'in_progress' => __( 'In Progress', 'ghca-acd' ),
      'completed'   => __( 'Completed', 'ghca-acd' ),
      'overdue'     => __( 'Overdue', 'ghca-acd' ),
    );
    return $map[ $status ] ?? __( 'Unknown', 'ghca-acd' );
  }

  private static function format_due_date( string $raw ): string {
    $timestamp = strtotime( $raw );
    if ( ! $timestamp ) {
      return $raw;
    }
    return wp_date( 'F j, Y', $timestamp );
  }

  /** @return array<string,mixed> */
  private static function get_compliance_cycle( int $user_id ): array {
    $start = self::get_platform_start_timestamp( $user_id );
    if ( ! $start ) {
      return array(
        'due_timestamp'  => strtotime( get_option( GHCA_Admin_Compliance_Dashboard::OPTION_DUE_DATE, '2026-07-31' ) ) ?: 0,
        'due_date_label' => self::format_due_date( get_option( GHCA_Admin_Compliance_Dashboard::OPTION_DUE_DATE, '2026-07-31' ) ),
      );
    }

    $now           = time();
    $days_on       = (int) floor( max( 0, $now - $start ) / DAY_IN_SECONDS );
    $cycle_number  = (int) floor( $days_on / GHCA_Admin_Compliance_Dashboard::CYCLE_DAYS ) + 1;
    $due_timestamp = $start + ( $cycle_number * GHCA_Admin_Compliance_Dashboard::CYCLE_DAYS * DAY_IN_SECONDS );

    return array(
      'due_timestamp'  => $due_timestamp,
      'due_date_label' => wp_date( 'F j, Y', $due_timestamp ),
    );
  }

  private static function get_platform_start_timestamp( int $user_id ): int {
    $user = get_userdata( $user_id );
    if ( ! $user ) {
      return 0;
    }

    $candidates = array( strtotime( $user->user_registered ) );
    foreach ( self::get_compliance_group_ids() as $group_id ) {
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

  public static function get_page_url( string $slug, string $fallback ): string {
    if ( isset( self::$page_url_cache[ $slug ] ) ) {
      return self::$page_url_cache[ $slug ];
    }

    $page = get_page_by_path( $slug );
    $url  = $page ? get_permalink( $page ) : home_url( $fallback );

    self::$page_url_cache[ $slug ] = $url;
    return $url;
  }

  private static function sanitize_orderby( string $value ): string {
    return in_array( $value, GHCA_Admin_Compliance_Dashboard::SORTABLE_COLUMNS, true ) ? $value : '';
  }

  /** Progress-bar colour modifier based on completion percent. */
  public static function get_progress_class( int $pct ): string {
    if ( $pct >= 80 ) {
      return 'ghca-acd__progress-bar--success';
    }
    if ( $pct >= 50 ) {
      return 'ghca-acd__progress-bar--warning';
    }
    return 'ghca-acd__progress-bar--danger';
  }

  /** Two-letter initials for an employee avatar. */
  public static function get_avatar_initials( string $name ): string {
    $parts    = preg_split( '/\s+/', trim( $name ) ) ?: array();
    $initials = '';
    foreach ( $parts as $part ) {
      if ( $part === '' ) {
        continue;
      }
      $initials .= mb_strtoupper( mb_substr( $part, 0, 1 ) );
      if ( mb_strlen( $initials ) >= 2 ) {
        break;
      }
    }
    return $initials !== '' ? $initials : '–';
  }

  /** Deterministic, calm avatar tint derived from the user id. */
  public static function get_avatar_style( int $user_id ): string {
    $palette = array(
      array( '#eef6fc', '#176cad' ),
      array( '#ecfdf3', '#067647' ),
      array( '#fffaeb', '#b54708' ),
      array( '#f4f0ff', '#6938ef' ),
      array( '#fef3f2', '#b42318' ),
      array( '#eefcfb', '#0e7090' ),
    );
    list( $bg, $fg ) = $palette[ $user_id % count( $palette ) ];
    return sprintf( 'background:%s;color:%s;', $bg, $fg );
  }
}
