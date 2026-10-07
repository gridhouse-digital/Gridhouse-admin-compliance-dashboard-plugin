<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
require_once __DIR__ . '/class-packet-course-selection.php';

/**
 * GHCA_Audit_Calculator
 * 
 * Shared calculation logic for ODP Audit Compliance.
 * Calculates anniversary windows, handles course mappings, and aggregates hours
 * for a specific employee. Used by both CSV exports and PDF generation.
 */
require_once __DIR__ . '/class-employment-record.php';
require_once __DIR__ . '/class-audit-mapping.php';
require_once __DIR__ . '/class-odp-applicability.php';

final class GHCA_Audit_Calculator {

	/** Inclusive local dates, with an exclusive upper timestamp. */
	public static function reporting_window( array $period ): array {
		$dates = array();
		foreach ( array( 'start', 'end' ) as $key ) {
			$value = $period[ $key ] ?? null;
			$date = is_string( $value ) && preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value )
				? DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() ) : false;
			if ( ! $date || $date->format( 'Y-m-d' ) !== $value || $date->getTimestamp() <= 0 ) {
				throw new InvalidArgumentException( 'Enter valid audit start and end dates.' );
			}
			$dates[ $key ] = $date;
		}
		if ( $dates['end'] < $dates['start'] ) {
			throw new InvalidArgumentException( 'Audit end date must be on or after the start date.' );
		}
		return array( 'start_ts' => $dates['start']->getTimestamp(), 'end_ts' => $dates['end']->modify( '+1 day' )->getTimestamp(), 'start_date' => $dates['start']->format( 'm/d/Y' ), 'end_date' => $dates['end']->format( 'm/d/Y' ) );
	}

	public static function registration_timestamp( $user_info ): int {
		if ( ! $user_info || empty( $user_info->user_registered ) ) {
			return 0;
		}

		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$registered = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $user_info->user_registered, $timezone );

		return $registered ? $registered->getTimestamp() : 0;
	}

	/**
	 * Return the active annual period. The end timestamp is exclusive.
	 */
	public static function resolve_annual_window( int $doh_ts, string $annual_cycle, int $now_ts, ?DateTimeZone $timezone = null ): array {
		if ( $doh_ts <= 0 || ! in_array( $annual_cycle, array( 'calendar_year', 'employee_start_date' ), true ) ) {
			return array( 'start_ts' => 0, 'end_ts' => 0, 'start_date' => '', 'end_date' => '' );
		}
		$timezone = $timezone ?: new DateTimeZone( 'UTC' );
		$hire = ( new DateTimeImmutable( '@' . $doh_ts ) )->setTimezone( $timezone )->setTime( 0, 0 );
		$now  = ( new DateTimeImmutable( '@' . $now_ts ) )->setTimezone( $timezone )->setTime( 0, 0 );

		if ( 'calendar_year' === $annual_cycle ) {
			$year = (int) $now->format( 'Y' );
			if ( $hire > $now ) {
				$year = (int) $hire->format( 'Y' );
			}
			$start = new DateTimeImmutable( $year . '-01-01 00:00:00', $timezone );
			$end   = $start->modify( '+1 year' );
			if ( $hire > $start ) {
				$start = $hire;
			}
		} else {
			$hire_year  = (int) $hire->format( 'Y' );
			$hire_month = (int) $hire->format( 'n' );
			$hire_day   = (int) $hire->format( 'j' );
			$now_year   = (int) $now->format( 'Y' );

			if ( $now < $hire ) {
				$start = $hire;
				$end = self::anniversary( $hire_year + 1, $hire_month, $hire_day, $timezone );
			} else {
				$this_year = self::anniversary( $now_year, $hire_month, $hire_day, $timezone );
				if ( $now >= $this_year ) {
					$start = $this_year;
					$end = self::anniversary( $now_year + 1, $hire_month, $hire_day, $timezone );
				} else {
					$start = self::anniversary( $now_year - 1, $hire_month, $hire_day, $timezone );
					$end = $this_year;
				}
				if ( $start < $hire ) {
					$start = $hire;
				}
			}
		}

		return array(
			'start_ts'   => $start->getTimestamp(),
			'end_ts'     => $end->getTimestamp(),
			'start_date' => $start->format( 'm/d/Y' ),
			'end_date'   => $end->modify( '-1 day' )->format( 'm/d/Y' ),
		);
	}

	public static function annual_status( float $hours, int $now_ts, int $end_ts ): string {
		if ( $hours >= 24 ) {
			return 'compliant';
		}

		return $now_ts >= $end_ts ? 'noncompliant' : 'in_progress';
	}

	public static function short_description( string $description, int $limit = 300 ): string {
		$description = html_entity_decode( strip_tags( $description ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$description = trim( (string) preg_replace( '/\s+/u', ' ', $description ) );
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $description ) : strlen( $description );
		if ( $length <= $limit ) {
			return $description;
		}

		$description = function_exists( 'mb_substr' ) ? mb_substr( $description, 0, max( 0, $limit - 3 ) ) : substr( $description, 0, max( 0, $limit - 3 ) );
		return rtrim( $description ) . '...';
	}

	public static function course_short_description( int $course_id ): string {
		return self::short_description( self::course_description_source( $course_id ) );
	}

	public static function course_full_description( int $course_id ): string {
		$description = self::course_description_source( $course_id );
		$description = (string) preg_replace( '/<\s*br\s*\/?\s*>|<\/(?:p|div|li|h[1-6])\s*>/iu', "\n", $description );
		$description = html_entity_decode( strip_tags( $description ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$description = (string) preg_replace( '/[ \t]+/u', ' ', $description );
		$description = (string) preg_replace( '/ *\R */u', "\n", $description );
		$description = (string) preg_replace( '/\n{3,}/u', "\n\n", $description );

		return trim( $description );
	}

	private static function course_description_source( int $course_id ): string {
		$description = (string) get_post_meta( $course_id, '_learndash_course_grid_short_description', true );
		if ( '' === trim( $description ) && function_exists( 'learndash_get_setting' ) ) {
			$description = (string) learndash_get_setting( $course_id, 'course_short_description' );
		}
		if ( '' === trim( $description ) ) {
			$description = (string) get_post_field( 'post_excerpt', $course_id );
		}

		return $description;
	}

	public static function required_hours( array $mappings, string $tracker_type ): array {
		$hours = array();
		foreach ( $mappings as $config ) {
			$category = $config['odp_category'] ?? '';
			if ( '' === $category || ( 'orientation' === $tracker_type && empty( $config['is_orientation'] ) ) ) {
				continue;
			}
			$hours[ $category ] = ( $hours[ $category ] ?? 0 ) + floatval( $config['credit_hours'] ?? 0 );
		}

		return $hours;
	}

	public static function category_status( bool $completed, bool $mapped, bool $invalid, bool $conditional, array $decision = array() ): string {
		$state = $decision['state'] ?? 'review';
		$has_reason = is_string( $decision['reason'] ?? null ) && '' !== trim( $decision['reason'] );
		if ( $conditional && 'not_applicable' === $state && $has_reason ) { return 'Not applicable'; }
		if ( $invalid ) { return 'Needs review'; }
		if ( $completed ) { return 'Completed'; }
		if ( ! $mapped || ( $conditional && ( 'applicable' !== $state || ! $has_reason ) ) ) { return 'Needs review'; }
		return 'Missing training';
	}

	public static function category_display( array $data, string $category ): string {
		if ( 'individual_plan' === $category && ! empty( $data['agency_training']['isp'] ) && ( $data['category_statuses']['individual_plan'] ?? '' ) === 'Completed — manually verified' ) {
			return 'Completed in person — agency confirmed';
		}
		if ( 'individual_plan' === $category && ! empty( $data['isp_manual_delivery'] ) ) {
			return 'Handled manually / in person — Supporting records are maintained by the agency and provided separately for audit. Delivery method only; completion is not verified by this label.';
		}
		$status = $data['category_statuses'][ $category ] ?? 'Needs review';
		$note = $data['category_notes'][ $category ] ?? '';
		return $status . ( '' !== $note ? ' — ' . $note : '' );
	}

	private static function anniversary( int $year, int $month, int $day, DateTimeZone $timezone ): DateTimeImmutable {
		return ( new DateTimeImmutable( 'now', $timezone ) )->setDate( $year, $month, $day )->setTime( 0, 0 );
	}

	/** Accept recorded absolute dates only; relative strings must never become evidence. */
	private static function completion_timestamp( $value ): int {
		if ( ! is_int( $value ) && ! is_string( $value ) ) {
			return 0;
		}
		if ( false !== strpos( (string) $value, "\0" ) ) {
			return 0;
		}
		$value = trim( (string) $value );
		if ( ctype_digit( $value ) ) {
			return (int) filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		}
		foreach ( array( 'Y-m-d H:i:s', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d', 'm/d/Y' ) as $format ) {
			$date = DateTimeImmutable::createFromFormat( '!' . $format, $value );
			if ( $date && $date->format( $format ) === $value ) {
				return max( 0, $date->getTimestamp() );
			}
		}
		return 0;
	}

	/**
	 * Calculate audit data for a single employee.
	 *
	 * @param array  $employee     The basic employee data array.
	 * @param string $tracker_type 'annual' or 'orientation'.
	 * @param array  $mappings     The audit mapping config (passed in to avoid N+1 DB calls).
	 *
	 * @return array Empty array if ignored, otherwise full metrics array.
	 */
	public static function calculate_employee_audit_data( array $employee, string $tracker_type, array $mappings, array $audit_period = array() ): array {
		$report_window = $audit_period ? self::reporting_window( $audit_period ) : array();
		if ( $audit_period && 'annual' !== $tracker_type ) {
			throw new InvalidArgumentException( 'An audit reporting period is only supported for annual packets.' );
		}
		$user_id = $employee['user_id'];
		$user_info = get_userdata( $user_id );
		
		// Split Name
		$name_parts = explode( ' ', $employee['name'], 2 );
		$first_name = $name_parts[0] ?? '';
		$last_name  = $name_parts[1] ?? '';
		
		// Flexible Role
		$role = 'Staff';
		$role_slug = '';
		if ( $user_info && ! empty( $user_info->roles ) ) {
			global $wp_roles;
			$role_slug = $user_info->roles[0];
			$role = isset( $wp_roles->roles[ $role_slug ] ) ? $wp_roles->roles[ $role_slug ]['name'] : $role_slug;
		}

		$ignored_roles = apply_filters( 'ghca_acd_audit_ignored_roles', array( 'administrator' ) );
		if ( in_array( $role_slug, $ignored_roles, true ) ) {
			return array();
		}

		if ( get_user_meta( $user_id, 'ghca_audit_exclude', true ) === '1' ) {
			return array();
		}

		// Account creation is not evidence of employment commencement.
		$doh_ts = GHCA_ACD_Employment_Record::timestamp( (int) $user_id );
		$doh = $doh_ts ? wp_date( 'm/d/Y', $doh_ts ) : '';
		
		// Timeline
		require_once dirname( __FILE__ ) . '/class-settings.php';
		$annual_cycle = GHCA_ACD_Settings::get_configured_annual_cycle();
		$now_ts = time();

		if ( $report_window || $doh_ts > 0 ) {
			$window = $report_window ?: self::resolve_annual_window( $doh_ts, $annual_cycle, $now_ts, wp_timezone() );
			$start_ts = $window['start_ts'];
			$end_ts = $window['end_ts'];
			$start_date = $window['start_date'];
			$end_date = $window['end_date'];
		} else {
			$start_ts = $end_ts = 0;
			$start_date = $end_date = '';
		}
		
		// Fetch Completions
		$metrics = array(
			'person_centered'     => array( 'hours' => 0, 'completed' => false, 'date' => 0 ),
			'abuse_prevention'    => array( 'hours' => 0, 'completed' => false, 'date' => 0 ),
			'individual_rights'   => array( 'hours' => 0, 'completed' => false, 'date' => 0 ),
			'reporting_incidents' => array( 'hours' => 0, 'completed' => false, 'date' => 0 ),
			'behavior_supports'   => array( 'hours' => 0, 'completed' => false, 'date' => 0 ),
			'individual_plan'     => array( 'hours' => 0, 'completed' => false, 'date' => 0 ),
			'job_related'         => array( 'hours' => 0, 'completed' => false, 'date' => 0 ),
			'general'             => array( 'hours' => 0, 'completed' => false, 'date' => 0 ),
			'odp_annual_training' => array( 'hours' => 0, 'completed' => false, 'date' => 0 ),
		);
		$required_hours = self::required_hours( $mappings, $tracker_type );

		$orientation_completed_date = 0;
		$all_orientation_met = true;
		$enrolled_course_ids = null;
		if ( function_exists( 'learndash_user_get_enrolled_courses' ) ) {
			$enrolled_course_ids = array_fill_keys(
				array_map( 'intval', (array) learndash_user_get_enrolled_courses( $user_id ) ),
				true
			);
		}
		
		// Collect raw completion info to pass down to PDF (so PDF knows which certificates to fetch)
		$raw_completed_courses = array();
		$evidence_issues = array();
		if ( $doh_ts <= 0 && ! $audit_period ) {
			$evidence_issues[] = 'Verified employment start date is missing; annual window and hire-relative timing cannot be assessed.';
		}
		if ( '' === $annual_cycle && 'annual' === $tracker_type && ! $audit_period ) {
			$evidence_issues[] = 'Annual training-cycle setting is missing or unsupported; annual credit cannot be assessed.';
		}
		$orientation_date_missing = false;
		$mapped_categories = array();
		$invalid_categories = array();

		foreach ( $mappings as $course_id => $config ) {
			$cat = $config['odp_category'] ?? '';
			$coverage = GHCA_Audit_Mapping::coverage_categories( $config );
			$hrs = max( 0, floatval( $config['credit_hours'] ?? 0 ) );
			$is_orient = ! empty( $config['is_orientation'] );
			if ( 'annual' === $tracker_type || $is_orient ) {
				foreach ( $coverage as $covered ) { $mapped_categories[ $covered ] = true; }
			}
			$completed = function_exists( 'learndash_course_completed' ) ? learndash_course_completed( $user_id, $course_id ) : false;
			$raw_date = get_user_meta( $user_id, 'course_completed_' . $course_id, true );
			$completed_ts = self::completion_timestamp( $raw_date );

			// Try to fallback to native LearnDash functions for dates if meta is empty
			if ( $completed && empty( $raw_date ) && function_exists( 'learndash_user_get_course_completed_date' ) ) {
				$completed_ts = self::completion_timestamp( learndash_user_get_course_completed_date( $user_id, $course_id ) );
			}

			if ( ! $completed_ts && ( $completed || ! empty( $raw_date ) ) ) {
				if ( array_key_exists( $cat, $metrics ) && ( 'annual' === $tracker_type || $is_orient ) ) {
					$evidence_issues[] = 'LearnDash course #' . (int) $course_id . ': missing or invalid completion date; credit excluded.';
					foreach ( $coverage as $covered ) { $invalid_categories[ $covered ] = true; }
				}
				if ( $is_orient ) {
					$orientation_date_missing = true;
					$all_orientation_met = false;
				}
			}

			// A completion flag alone cannot establish a date or a creditable cycle.
			if ( $completed_ts > 0 ) {
				
				// For annual reports, only count if completed within the current anniversary window
				$valid_for_annual = true;
				if ( 'annual' === $tracker_type ) {
					if ( $completed_ts < $start_ts || $completed_ts >= $end_ts ) {
						$valid_for_annual = false;
					}
				}
				
				$valid_for_tracker = $valid_for_annual && ( 'annual' === $tracker_type || $is_orient );
				if ( $valid_for_tracker && array_key_exists( $cat, $metrics ) ) {
					$metrics[ $cat ]['hours'] += $hrs;
					foreach ( $coverage as $covered ) {
						$metrics[ $covered ]['completed'] = true;
						$metrics[ $covered ]['date'] = max( $metrics[ $covered ]['date'], $completed_ts );
					}
					
					// Store raw completion for PDF details and certificate fetching.
					$raw_completed_courses[] = array(
						'course_id'    => $course_id,
						'course_key'   => 'learndash:' . $course_id,
						'source'       => 'learndash',
						'date'         => $completed_ts,
						'title'        => get_the_title( $course_id ),
						'description'  => self::course_short_description( (int) $course_id ),
						'full_description' => self::course_full_description( (int) $course_id ),
						'category'     => $cat,
						'categories'   => $coverage,
						'credit_hours' => $hrs,
					);
				}

				if ( $is_orient ) {
					if ( $completed_ts > $orientation_completed_date ) {
						$orientation_completed_date = $completed_ts;
					}
				}
			} else {
				if (
					! empty( $config['is_orientation'] )
					&& ( null === $enrolled_course_ids || isset( $enrolled_course_ids[ (int) $course_id ] ) )
				) {
					$all_orientation_met = false;
				}
			}
		}

		// Orientation inclusion is explicitly approved in the immutable snapshot.
		if ( in_array( $tracker_type, array( 'annual', 'orientation' ), true ) && class_exists( 'GHCA_ACD_Jotform_Repository' ) ) {
			foreach ( GHCA_ACD_Jotform_Repository::approved_courses( $user_id ) as $external ) {
				$snapshot = $external['snapshot'];
				if ( 'orientation' === $tracker_type && true !== ( $snapshot['applies_to_orientation'] ?? false ) ) {
					continue;
				}
				$category = sanitize_key( (string) ( $snapshot['category'] ?? '' ) );
				$hours = (float) ( $snapshot['credit_hours'] ?? 0 );
				$raw_date = (string) ( $snapshot['completion_date'] ?? $external['proposed_completion_date'] ?? '' );
				$date = preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $raw_date ) ? DateTimeImmutable::createFromFormat( '!Y-m-d', $raw_date, $audit_period ? wp_timezone() : new DateTimeZone( 'UTC' ) ) : false;
				$completed_ts = $date && $date->format( 'Y-m-d' ) === $raw_date ? $date->setTime( 12, 0 )->getTimestamp() : 0;
				if ( ! $completed_ts && array_key_exists( $category, $metrics ) && ( $hours > 0 || 'orientation' === $tracker_type ) ) {
					$evidence_issues[] = 'External revision #' . (int) $external['revision_id'] . ': missing or invalid completion date; credit excluded.';
					$invalid_categories[ $category ] = true;
					if ( 'orientation' === $tracker_type ) { $orientation_date_missing = true; $all_orientation_met = false; }
				}
				if ( ! $completed_ts || ! array_key_exists( $category, $metrics ) || $hours < 0 || ( 'annual' === $tracker_type && ( $completed_ts < $start_ts || $completed_ts >= $end_ts || $hours <= 0 ) ) ) {
					continue;
				}
				$metrics[ $category ]['hours'] += $hours;
				$mapped_categories[ $category ] = true;
				if ( $hours > 0 ) {
					$metrics[ $category ]['completed'] = true;
					$metrics[ $category ]['date'] = max( $metrics[ $category ]['date'], $completed_ts );
					if ( 'orientation' === $tracker_type ) {
						$orientation_completed_date = max( $orientation_completed_date, $completed_ts );
					}
				}
				$raw_completed_courses[] = array(
					'course_id' => 0,
					'course_key' => 'external:' . (int) $external['revision_id'],
					'source' => 'external',
					'training_id' => (int) $external['training_id'],
					'catalog_id' => (int) ( $snapshot['catalog_id'] ?? 0 ),
					'evidence_revision_id' => (int) $external['revision_id'],
					'evidence_snapshot_digest' => (string) $external['snapshot_digest'],
					'evidence_manifest' => $external['manifest'],
					'date' => $completed_ts,
					'title' => (string) ( $snapshot['title'] ?? '' ),
					'provider' => (string) ( $snapshot['provider'] ?? '' ),
					'description' => (string) ( $snapshot['description'] ?? '' ),
					'full_description' => (string) ( $snapshot['description'] ?? '' ),
					'category' => $category,
					'credit_hours' => $hours,
					'entry_method' => sanitize_key( (string) ( $snapshot['entry_method'] ?? 'jotform' ) ),
					'entered_by' => (int) ( $snapshot['entered_by'] ?? 0 ),
					'entry_reason' => (string) ( $snapshot['entry_reason'] ?? '' ),
				);
			}
			usort( $raw_completed_courses, static function ( array $a, array $b ): int { $date = (int) $a['date'] <=> (int) $b['date']; return 0 !== $date ? $date : strcasecmp( (string) $a['title'], (string) $b['title'] ); } );
		}

		$period = $start_date && $end_date ? $start_date . ' to ' . $end_date : '';
		$decisions = 'annual' === $tracker_type && ! $audit_period ? GHCA_ODP_Applicability::current( (int) $user_id, $period ) : array();
		$agency_training = 'annual' === $tracker_type ? GHCA_Agency_Training::credited( (int) $user_id, $start_ts, $end_ts ) : array();
		if ( isset( $agency_training['isp'] ) ) {
			// Do not add a second set of ISP hours over already credited course evidence or an exemption.
			$agency_training['isp']['counted_hours'] = $metrics['individual_plan']['hours'] > 0 || ( $decisions['individual_plan']['state'] ?? '' ) === 'not_applicable' ? 0 : (float) $agency_training['isp']['hours'];
			$metrics['individual_plan']['hours'] += $agency_training['isp']['counted_hours'];
		}
		if ( isset( $agency_training['communication'] ) ) { $metrics['general']['hours'] += (float) $agency_training['communication']['hours']; }
		$total_annual_hrs = 0;
		foreach ( $metrics as $key => $data ) {
			if ( ! in_array( $key, array( 'general', 'job_related', 'odp_annual_training' ), true ) ) {
				$total_annual_hrs += $data['hours'];
			}
		}
		
		// Broad annual training contributes hours, not evidence of a specific required topic.
		$additional_hrs = $metrics['general']['hours'] + $metrics['job_related']['hours'] + $metrics['odp_annual_training']['hours'];

		// Orientation within 30 days?
		$completed_within_30 = 'No';
		if ( $all_orientation_met && $orientation_completed_date > 0 && $doh_ts > 0 ) {
			$diff = ( $orientation_completed_date - $doh_ts ) / DAY_IN_SECONDS;
			if ( $diff <= 30 ) {
				$completed_within_30 = 'Yes';
			}
		}

		// Worked Alone Verification
		$worked_alone_date_raw = get_user_meta( $user_id, 'ghca_worked_alone_date', true );
		$worked_alone_compliant = 'N/A';
		if ( ! empty( $worked_alone_date_raw ) && $orientation_completed_date > 0 ) {
			$worked_alone_ts = strtotime( $worked_alone_date_raw );
			if ( $worked_alone_ts >= $orientation_completed_date ) {
				$worked_alone_compliant = 'Yes';
			} else {
				$worked_alone_compliant = 'No (Violation)';
			}
		}

		// Annual Compliance Verification
		$annual_status = self::annual_status( $total_annual_hrs + $additional_hrs, $now_ts, $end_ts );
		if ( $evidence_issues ) {
			$annual_status = 'missing_evidence';
		}
		if ( $orientation_date_missing ) {
			$orientation_completed_date = 0;
			$completed_within_30 = 'Missing evidence';
			$worked_alone_compliant = 'Missing evidence';
		}
		if ( $doh_ts <= 0 ) {
			$completed_within_30 = 'Missing evidence';
		}

		$period = $start_date && $end_date ? $start_date . ' to ' . $end_date : '';
		$decisions = 'annual' === $tracker_type && ! $audit_period ? GHCA_ODP_Applicability::current( (int) $user_id, $period ) : array();
		$category_statuses = array();
		$category_notes = array();
		foreach ( $metrics as $category => $metric ) {
			$conditional = 'annual' === $tracker_type && array_key_exists( $category, GHCA_ODP_Applicability::categories() );
			$invalid = isset( $invalid_categories[ $category ] ) || ( 'annual' === $tracker_type && ( $doh_ts <= 0 || '' === $annual_cycle ) );
			$category_statuses[ $category ] = self::category_status( $metric['completed'], isset( $mapped_categories[ $category ] ), $invalid, $conditional, $decisions[ $category ] ?? array() );
			if ( $audit_period ) {
				$category_statuses[ $category ] = isset( $invalid_categories[ $category ] ) ? 'Evidence review required' : ( $metric['completed'] ? 'Recorded in period' : 'No completion recorded in period' );
			}
			if ( $conditional && ! empty( $decisions[ $category ]['reason'] ) ) {
				$category_notes[ $category ] = $decisions[ $category ]['reason'];
			}
		}
		$manual_isp = $decisions['isp_evidence'] ?? array();
		$external_isp = false;
		if ( 'annual' === $tracker_type && $manual_isp && ( $manual_isp['state'] ?? 'none' ) !== 'none' ) {
			$validated_isp = GHCA_ISP_Evidence_Review::sanitize( $manual_isp, $manual_isp, (int) $user_id );
			$manual_valid = ! is_wp_error( $validated_isp ) && ( 'verified' !== ( $manual_isp['state'] ?? '' ) || ( $decisions['individual_plan']['state'] ?? '' ) === 'applicable' );
			$category_statuses['individual_plan'] = $manual_valid ? GHCA_ISP_Evidence_Review::status( $manual_isp ) : 'Needs review — agency-managed evidence';
			$category_notes['individual_plan'] = 'Agency-held evidence; no attachment or automatic hours. Retrieve the signed form and scope register from the secure agency records.';
			$external_isp = $manual_valid && 'managed' === $manual_isp['state'];
			if ( $external_isp ) {
				$category_notes['individual_plan'] = 'The agency has selected separate handling of ISP implementation evidence. This evidence has not been verified within the platform and is not attached to this packet. The agency must supply the supporting records separately through an appropriate secure channel and confirm that they document training for this employee and the relevant plans. An acknowledgment of receipt alone does not establish training. ISP coverage remains unresolved; no hours or completion are awarded by this selection.';
			}
			if ( $manual_valid && 'verified' === $manual_isp['state'] ) {
				$category_notes['individual_plan'] .= ' Verified by user #' . (int) $manual_isp['verified_by'] . ' on ' . wp_date( 'Y-m-d', (int) $manual_isp['verified_at'] ) . '.';
			}
		}
		// Hours alone must not certify an unresolved annual topic matrix.
		if ( isset( $agency_training['isp'] ) && ! isset( $invalid_categories['individual_plan'] ) && 'Not applicable' !== $category_statuses['individual_plan'] && ! $evidence_issues ) {
			$category_statuses['individual_plan'] = 'Completed — manually verified';
			$category_notes['individual_plan'] = 'Agency-confirmed in-person training. Supporting records are maintained by the agency and provided separately for audit.';
			$external_isp = false;
			$metrics['individual_plan']['completed'] = true;
		}
		if ( 'annual' === $tracker_type && 'compliant' === $annual_status ) {
			foreach ( array( 'person_centered', 'abuse_prevention', 'individual_rights', 'reporting_incidents', 'behavior_supports', 'individual_plan' ) as $category ) {
				if ( ! in_array( $category_statuses[ $category ], array( 'Completed', 'Completed — manually verified', 'Not applicable' ), true ) ) { $annual_status = 'needs_review'; break; }
			}
		}

		return array(
			'last_name'           => $last_name,
			'first_name'          => $first_name,
			'role'                => $role,
			'doh'                 => $doh,
			'start_date'          => $start_date,
			'end_date'            => $end_date,
			'first_service_date'  => get_user_meta( $user_id, 'ghca_first_service_date', true ),
			'worked_alone_date'   => $worked_alone_date_raw,
			'worked_alone_compliant' => $worked_alone_compliant,
			'annual_status'       => $audit_period ? 'reporting_only' : $annual_status,
			'audit_period'        => $audit_period,
			'annual_cycle'        => $annual_cycle,
			'category_statuses'   => $category_statuses,
			'category_notes'      => $category_notes,
			'external_isp_evidence' => $external_isp,
			'agency_training' => $agency_training,
			'communication_manual_delivery' => 'annual' === $tracker_type && ( GHCA_Agency_Training::enabled( 'communication' ) || isset( $agency_training['communication'] ) ),
			'isp_manual_delivery' => 'annual' === $tracker_type && 'Not applicable' !== $category_statuses['individual_plan'] && in_array( get_option( GHCA_ACD_Settings::OPTION_ISP_MANUAL_DELIVERY, 0 ), array( 1, '1' ), true ),
			
			// Formatted Marks
			'person_centered' => $metrics['person_centered']['completed'] ? 'Yes' : 'No',
			'abuse'           => $metrics['abuse_prevention']['completed'] ? 'Yes' : 'No',
			'rights'          => $metrics['individual_rights']['completed'] ? 'Yes' : 'No',
			'incidents'       => $metrics['reporting_incidents']['completed'] ? 'Yes' : 'No',
			'behavior'        => $metrics['behavior_supports']['completed'] ? 'Yes' : 'No',
			'isp'             => $metrics['individual_plan']['completed'] ? 'Yes' : 'No',
			'job_related'     => $metrics['job_related']['completed'] ? 'Yes' : 'No',
			'requirement_hours' => array(
				'person_centered'     => array( 'required' => $required_hours['person_centered'] ?? 0, 'achieved' => $metrics['person_centered']['hours'], 'date' => (int) $metrics['person_centered']['date'] ),
				'abuse_prevention'    => array( 'required' => $required_hours['abuse_prevention'] ?? 0, 'achieved' => $metrics['abuse_prevention']['hours'], 'date' => (int) $metrics['abuse_prevention']['date'] ),
				'individual_rights'   => array( 'required' => $required_hours['individual_rights'] ?? 0, 'achieved' => $metrics['individual_rights']['hours'], 'date' => (int) $metrics['individual_rights']['date'] ),
				'reporting_incidents' => array( 'required' => $required_hours['reporting_incidents'] ?? 0, 'achieved' => $metrics['reporting_incidents']['hours'], 'date' => (int) $metrics['reporting_incidents']['date'] ),
				'behavior_supports'   => array( 'required' => $required_hours['behavior_supports'] ?? 0, 'achieved' => $metrics['behavior_supports']['hours'], 'date' => (int) $metrics['behavior_supports']['date'] ),
				'individual_plan'     => array( 'required' => $required_hours['individual_plan'] ?? 0, 'achieved' => $metrics['individual_plan']['hours'], 'date' => (int) $metrics['individual_plan']['date'] ),
				'job_related'         => array( 'required' => $required_hours['job_related'] ?? 0, 'achieved' => $metrics['job_related']['hours'], 'date' => (int) $metrics['job_related']['date'] ),
			),
			
			'total_annual_hrs'    => $total_annual_hrs,
			'additional_hrs'      => $additional_hrs,
			'total_hrs'           => $total_annual_hrs + $additional_hrs,
			
			'completion_date'     => $orientation_completed_date ? gmdate( 'm/d/Y', $orientation_completed_date ) : '',
			'completed_within_30' => $completed_within_30,
			
			// Raw data for PDF
			'raw_completed_courses' => $raw_completed_courses,
			'agency_selected_courses' => GHCA_Packet_Course_Selection::rows( $raw_completed_courses, $tracker_type ),
			'evidence_issues' => $evidence_issues,
		);
	}
}
