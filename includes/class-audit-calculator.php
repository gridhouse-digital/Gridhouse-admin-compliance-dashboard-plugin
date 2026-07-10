<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GHCA_Audit_Calculator
 * 
 * Shared calculation logic for ODP Audit Compliance.
 * Calculates anniversary windows, handles course mappings, and aggregates hours
 * for a specific employee. Used by both CSV exports and PDF generation.
 */
final class GHCA_Audit_Calculator {

	/**
	 * Calculate audit data for a single employee.
	 *
	 * @param array  $employee     The basic employee data array.
	 * @param string $tracker_type 'annual' or 'orientation'.
	 * @param array  $mappings     The audit mapping config (passed in to avoid N+1 DB calls).
	 *
	 * @return array Empty array if ignored, otherwise full metrics array.
	 */
	public static function calculate_employee_audit_data( array $employee, string $tracker_type, array $mappings ): array {
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

		// DOH
		$doh_ts = GHCA_Compliance_Program::get_enrollment_timestamp( $user_id, GHCA_Compliance_Program::get_user_new_hire_group_ids( $user_id ) );
		if ( ! $doh_ts && $user_info ) {
			$doh_ts = strtotime( $user_info->user_registered );
		}
		$doh = $doh_ts ? gmdate( 'm/d/Y', $doh_ts ) : '';
		
		// Timeline
		$start_ts = 0;
		$end_ts = 0;
		$annual_status = 'not_due';
		
		require_once dirname( __FILE__ ) . '/class-settings.php';
		$annual_cycle = GHCA_ACD_Settings::get_annual_cycle();
		
		if ( $doh_ts > 0 ) {
			$current_year = (int) gmdate( 'Y' );
			$doh_month = (int) gmdate( 'n', $doh_ts );
			$doh_day   = (int) gmdate( 'j', $doh_ts );
			$doh_year  = (int) gmdate( 'Y', $doh_ts );

			if ( 'calendar_year' === $annual_cycle ) {
				// First full calendar year starts Jan 1 of the year AFTER hire.
				$first_full_year_start = gmmktime( 0, 0, 0, 1, 1, $doh_year + 1 );
				$first_full_year_end = gmmktime( 0, 0, 0, 12, 31, $doh_year + 1 );

				if ( time() <= $first_full_year_end ) {
					// They haven't finished their first full calendar year.
					$annual_status = 'not_due';
					// Show current active window
					if ( time() < $first_full_year_start ) {
						$start_ts = gmmktime( 0, 0, 0, 1, 1, $doh_year );
						$end_ts = gmmktime( 0, 0, 0, 12, 31, $doh_year );
					} else {
						$start_ts = $first_full_year_start;
						$end_ts = $first_full_year_end;
					}
				} else {
					// They have completed at least one full calendar year.
					// Use the last completed year for audit.
					$start_ts = gmmktime( 0, 0, 0, 1, 1, $current_year - 1 );
					$end_ts   = gmmktime( 0, 0, 0, 12, 31, $current_year - 1 );
					$annual_status = 'due';
				}
			} else {
				// employee_start_date (anniversary)
				$first_full_year_end = gmmktime( 0, 0, 0, $doh_month, $doh_day, $doh_year + 1 );

				if ( time() <= $first_full_year_end ) {
					// They haven't finished their first full anniversary year.
					$annual_status = 'not_due';
					$start_ts = $doh_ts;
					$end_ts = $first_full_year_end;
				} else {
					// They have completed at least one full anniversary year.
					$anniversary_this_year = gmmktime( 0, 0, 0, $doh_month, $doh_day, $current_year );
					
					if ( time() >= $anniversary_this_year ) {
						// The anniversary happened this year, so the last completed cycle ended this year.
						$start_ts = gmmktime( 0, 0, 0, $doh_month, $doh_day, $current_year - 1 );
						$end_ts   = $anniversary_this_year;
					} else {
						// The anniversary hasn't happened yet this year, so the last completed cycle ended last year.
						$start_ts = gmmktime( 0, 0, 0, $doh_month, $doh_day, $current_year - 2 );
						$end_ts   = gmmktime( 0, 0, 0, $doh_month, $doh_day, $current_year - 1 );
					}
					$annual_status = 'due';
				}
			}
			$start_date = gmdate( 'm/d/Y', $start_ts );
			$end_date   = gmdate( 'm/d/Y', $end_ts );
		} else {
			$start_ts = strtotime( '-1 year' );
			$end_ts = time();
			$start_date = gmdate( 'm/d/Y', $start_ts );
			$end_date   = gmdate( 'm/d/Y', $end_ts );
			$annual_status = 'due';
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
		);

		$orientation_completed_date = 0;
		$all_orientation_met = true;
		
		// Collect raw completion info to pass down to PDF (so PDF knows which certificates to fetch)
		$raw_completed_courses = array();

		foreach ( $mappings as $course_id => $config ) {
			$completed = function_exists( 'learndash_course_completed' ) ? learndash_course_completed( $user_id, $course_id ) : false;
			$completed_ts = get_user_meta( $user_id, 'course_completed_' . $course_id, true );

			// Try to fallback to native LearnDash functions for dates if meta is empty
			if ( $completed && empty( $completed_ts ) && function_exists( 'learndash_user_get_course_completed_date' ) ) {
				$completed_ts = learndash_user_get_course_completed_date( $user_id, $course_id );
			}
			
			if ( ! empty( $completed_ts ) && ! is_numeric( $completed_ts ) ) {
				$completed_ts = strtotime( $completed_ts );
			}

			// If either native function is true OR we have a valid timestamp
			if ( $completed || ( $completed_ts && is_numeric( $completed_ts ) ) ) {
				$completed_ts = (int) $completed_ts;
				if ( $completed_ts <= 0 ) {
					// Absolute fallback if course is complete but timestamp is inexplicably completely missing
					$completed_ts = time(); 
				}
				
				// Category mapping
				$cat = $config['odp_category'] ?? '';
				$hrs = floatval( $config['credit_hours'] ?? 0 );
				$is_orient = ! empty( $config['is_orientation'] );
				
				// For annual reports, only count if completed within the current anniversary window
				$valid_for_annual = true;
				if ( 'annual' === $tracker_type ) {
					if ( $completed_ts < $start_ts || $completed_ts > $end_ts ) {
						$valid_for_annual = false;
					}
				}
				
				if ( $valid_for_annual && array_key_exists( $cat, $metrics ) ) {
					$metrics[ $cat ]['hours'] += $hrs;
					$metrics[ $cat ]['completed'] = true;
					if ( $completed_ts > $metrics[ $cat ]['date'] ) {
						$metrics[ $cat ]['date'] = $completed_ts;
					}
					
					// Store raw completion for PDF certificate fetching
					$raw_completed_courses[] = array(
						'course_id' => $course_id,
						'date'      => $completed_ts,
						'title'     => get_the_title( $course_id ),
					);
				}

				if ( $is_orient ) {
					if ( $completed_ts > $orientation_completed_date ) {
						$orientation_completed_date = $completed_ts;
					}
				}
			} else {
				if ( ! empty( $config['is_orientation'] ) ) {
					$all_orientation_met = false;
				}
			}
		}

		$total_annual_hrs = 0;
		foreach ( $metrics as $key => $data ) {
			if ( 'general' !== $key && 'job_related' !== $key ) {
				$total_annual_hrs += $data['hours'];
			}
		}
		
		$additional_hrs = $metrics['general']['hours'] + $metrics['job_related']['hours'];

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
		if ( 'due' === $annual_status ) {
			if ( $total_annual_hrs + $additional_hrs >= 24 ) {
				$annual_status = 'compliant';
			} else {
				$annual_status = 'noncompliant';
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
			'annual_status'       => $annual_status,
			'annual_cycle'        => $annual_cycle,
			
			// Formatted Marks
			'person_centered' => $metrics['person_centered']['completed'] ? 'Yes' : 'No',
			'abuse'           => $metrics['abuse_prevention']['completed'] ? 'Yes' : 'No',
			'rights'          => $metrics['individual_rights']['completed'] ? 'Yes' : 'No',
			'incidents'       => $metrics['reporting_incidents']['completed'] ? 'Yes' : 'No',
			'behavior'        => $metrics['behavior_supports']['completed'] ? 'Yes' : 'No',
			'isp'             => $metrics['individual_plan']['completed'] ? 'Yes' : 'No',
			'job_related'     => $metrics['job_related']['completed'] ? 'Yes' : 'No',
			
			'total_annual_hrs'    => $total_annual_hrs,
			'additional_hrs'      => $additional_hrs,
			'total_hrs'           => $total_annual_hrs + $additional_hrs,
			
			'completion_date'     => $orientation_completed_date ? gmdate( 'm/d/Y', $orientation_completed_date ) : '',
			'completed_within_30' => $completed_within_30,
			
			// Raw data for PDF
			'raw_completed_courses' => $raw_completed_courses
		);
	}
}
