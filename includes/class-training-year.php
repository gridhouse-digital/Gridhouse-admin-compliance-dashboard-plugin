<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The audit training year as the single source of truth for due dates, annual hours and new-hire deadlines.
 *
 * Everything here is behind one switch (OPTION_ENABLED, off by default). While it is off the plugin behaves exactly
 * as before; the dry-run report (GHCA_ACD_Training_Year_Review) compares the two before an administrator enables it.
 *
 * When enabled:
 * - Dashboard due date (non-new-hires): the end of the employee's audit training year from
 *   GHCA_Audit_Calculator::resolve_annual_window(): the day before the next hire anniversary, or Dec 31 for the
 *   calendar-year cycle. "Hire date missing" when there is no verified employment date (no fallback date).
 * - Annual hours threshold: 24 or 12 by the employee's annual requirement (55 Pa. Code § 6100.143); 24 when not set.
 * - New-hire deadline: N calendar days after the verified hire date (optionally the first service date).
 * - Course lifespans: calendar days in the site time zone, and none for courses mapped to ODP annual categories.
 */
final class GHCA_ACD_Training_Year {
	const OPTION_ENABLED        = 'ghca_acd_training_year_rules';
	const OPTION_ENABLED_BY     = 'ghca_acd_training_year_rules_changed';
	const OPTION_NEW_HIRE_ANCHOR = 'ghca_acd_new_hire_anchor';
	const REQUIREMENT_META      = 'ghca_acd_training_requirement';
	const DEFAULT_HOURS         = 24;

	/** @var bool|null Test and dry-run override of the stored switch. */
	private static $override = null;

	public static function enabled(): bool {
		if ( null !== self::$override ) {
			return self::$override;
		}
		return function_exists( 'get_option' ) && in_array( get_option( self::OPTION_ENABLED, 0 ), array( 1, '1', true ), true );
	}

	/** Evaluate as if the switch were on (true), off (false), or as stored (null). Used by the dry run and tests. */
	public static function set_override( ?bool $value ): void {
		self::$override = $value;
	}

	/**
	 * Annual requirement choices: key => [label, hours]. 24 hours: direct support professionals, life sharers
	 * providing HCBS or base-funded services, direct supervisors of DSPs. 12 hours: the other staff § 6100.143 lists.
	 *
	 * @return array<string,array{0:string,1:int}>
	 */
	public static function requirements(): array {
		return array(
			'dsp'               => array( __( 'Direct support professional (24 h)', 'ghca-acd' ), 24 ),
			'life_sharer'       => array( __( 'Life sharer providing HCBS or base-funded services (24 h)', 'ghca-acd' ), 24 ),
			'dsp_supervisor'    => array( __( 'Direct supervisor of direct support professionals (24 h)', 'ghca-acd' ), 24 ),
			'management'        => array( __( 'Management, program, administrative or fiscal staff (12 h)', 'ghca-acd' ), 12 ),
			'ancillary'         => array( __( 'Dietary, housekeeping, maintenance or ancillary staff (12 h)', 'ghca-acd' ), 12 ),
			'other_12'          => array( __( 'Other § 6100.143 12-hour role, confirmed by the agency (12 h)', 'ghca-acd' ), 12 ),
		);
	}

	/** @return array{key:string,label:string,hours:int,set:bool} */
	public static function requirement( int $user_id ): array {
		$key     = $user_id > 0 && function_exists( 'get_user_meta' ) ? (string) get_user_meta( $user_id, self::REQUIREMENT_META, true ) : '';
		$choices = self::requirements();
		if ( isset( $choices[ $key ] ) ) {
			return array( 'key' => $key, 'label' => $choices[ $key ][0], 'hours' => (int) $choices[ $key ][1], 'set' => true );
		}
		return array( 'key' => '', 'label' => __( 'Not set — 24 h assumed', 'ghca-acd' ), 'hours' => self::DEFAULT_HOURS, 'set' => false );
	}

	/** Annual pass threshold. 24 while the switch is off (unchanged behaviour). */
	public static function required_hours( int $user_id ): int {
		return self::enabled() ? self::requirement( $user_id )['hours'] : self::DEFAULT_HOURS;
	}

	/** Saves one employee's requirement ('' clears it). Caller has checked nonce, capability and employee scope. */
	public static function save_requirement( int $user_id, string $key ) {
		if ( '' !== $key && ! isset( self::requirements()[ $key ] ) ) {
			return new WP_Error( 'ghca_requirement_invalid', __( 'Invalid annual training requirement.', 'ghca-acd' ) );
		}
		if ( $key === self::requirement( $user_id )['key'] ) {
			return false;
		}
		if ( '' === $key ) {
			return delete_user_meta( $user_id, self::REQUIREMENT_META ) ? true : new WP_Error( 'ghca_requirement_failed', __( 'The annual training requirement could not be cleared.', 'ghca-acd' ) );
		}
		return update_user_meta( $user_id, self::REQUIREMENT_META, $key ) ? true : new WP_Error( 'ghca_requirement_failed', __( 'The annual training requirement could not be saved.', 'ghca-acd' ) );
	}

	/**
	 * The employee's current audit training year.
	 *
	 * @return array{start_ts:int,end_ts:int,start_date:string,end_date:string,missing:string,label:string}
	 *   missing: '' | 'hire' (no verified employment date) | 'cycle' (annual cycle not configured)
	 */
	public static function window( int $user_id, ?int $now = null ): array {
		$empty = array( 'start_ts' => 0, 'end_ts' => 0, 'start_date' => '', 'end_date' => '', 'missing' => 'hire', 'label' => '' );
		$doh   = GHCA_ACD_Employment_Record::timestamp( $user_id );
		if ( $doh <= 0 ) {
			return $empty;
		}
		$cycle = GHCA_ACD_Settings::get_configured_annual_cycle();
		if ( '' === $cycle ) {
			return array( 'missing' => 'cycle' ) + $empty;
		}
		$window = GHCA_Audit_Calculator::resolve_annual_window( $doh, $cycle, $now ?? time(), wp_timezone() );
		$label  = wp_date( 'M j, Y', (int) $window['start_ts'] ) . ' – ' . wp_date( 'M j, Y', (int) $window['end_ts'] - 1 );
		return array( 'missing' => '', 'label' => $label ) + $window;
	}

	/**
	 * Dashboard due date: the last day of the training year. due_timestamp is the last second of that day in the
	 * site time zone (so the whole day is still "due", never overdue), 0 when it cannot be computed.
	 *
	 * @return array{due_timestamp:int,due_date_label:string,training_year_label:string}
	 */
	public static function due( int $user_id, ?int $now = null ): array {
		$window = self::window( $user_id, $now );
		if ( 'hire' === $window['missing'] ) {
			return array( 'due_timestamp' => 0, 'due_date_label' => __( 'Hire date missing', 'ghca-acd' ), 'training_year_label' => '' );
		}
		if ( 'cycle' === $window['missing'] ) {
			return array( 'due_timestamp' => 0, 'due_date_label' => __( 'Training year not configured', 'ghca-acd' ), 'training_year_label' => '' );
		}
		$due = (int) $window['end_ts'] - 1;
		return array( 'due_timestamp' => $due, 'due_date_label' => wp_date( 'F j, Y', $due ), 'training_year_label' => $window['label'] );
	}

	/** 'hire' (default) or 'hire_or_first_service'. */
	public static function new_hire_anchor_rule(): string {
		return 'hire_or_first_service' === ( function_exists( 'get_option' ) ? get_option( self::OPTION_NEW_HIRE_ANCHOR, 'hire' ) : 'hire' ) ? 'hire_or_first_service' : 'hire';
	}

	/**
	 * Start of the new-hire window: the verified hire date or, when the agency allows it (§ 6100.142(a): within
	 * 30 days after hire or after starting to provide a service), the recorded first service date if it is later.
	 *
	 * @return array{ts:int,basis:string} basis: hire | first_service | missing
	 */
	public static function new_hire_anchor( int $user_id ): array {
		$hire = GHCA_ACD_Employment_Record::timestamp( $user_id );
		if ( $hire <= 0 ) {
			return array( 'ts' => 0, 'basis' => 'missing' );
		}
		if ( 'hire_or_first_service' === self::new_hire_anchor_rule() ) {
			$first = GHCA_ACD_Employment_Record::date_timestamp( (string) get_user_meta( $user_id, 'ghca_first_service_date', true ) );
			if ( $first > $hire ) {
				return array( 'ts' => $first, 'basis' => 'first_service' );
			}
		}
		return array( 'ts' => $hire, 'basis' => 'hire' );
	}

	/** $ts plus $days calendar days in the site time zone (DST- and leap-year-safe). */
	public static function add_days( int $ts, int $days, ?DateTimeZone $timezone = null ): int {
		$timezone = $timezone ?: ( function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' ) );
		return ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $timezone )->modify( ( $days >= 0 ? '+' : '-' ) . abs( $days ) . ' days' )->getTimestamp();
	}

	/** Courses that earn annual ODP credit (any category in the audit mapping). Lifespans do not apply to them. */
	public static function is_annual_course( int $course_id ): bool {
		$mapping = function_exists( 'get_option' ) ? get_option( 'ghca_acd_audit_mapping', array() ) : array();
		return is_array( $mapping ) && '' !== (string) ( $mapping[ $course_id ]['odp_category'] ?? '' );
	}

	public static function sanitize_anchor( $value ): string {
		return 'hire_or_first_service' === $value ? 'hire_or_first_service' : 'hire';
	}
}
