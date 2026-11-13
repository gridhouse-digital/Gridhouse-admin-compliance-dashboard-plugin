<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'GHCA_Course_Lifespans', false ) ) {
	require_once __DIR__ . '/class-course-lifespans.php';
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
 * - Annual hours threshold: 24, 12 or not required, from the role map (each GHCA job role mapped per 55 Pa. Code
 *   § 6100.143; nurse roles 24 h as agency policy), a per-employee override, or 24 h flagged "Role requirement not
 *   set" when no role is mapped (requirement()['source']: override | role_map | default).
 * - New-hire deadline: N calendar days after the verified hire date (optionally the first service date).
 * - Course lifespans: calendar days in the site time zone, and none for courses mapped to ODP annual categories.
 *
 * Employee API (stable contract; the employee plugin calls only these, guarded by class_exists / method_exists):
 * enabled(), set_override(), due(), window(), requirement(), required_hours(), course(), is_annual_course().
 * Do not rename them or change their return keys. See docs/training-year-rules-2.0.md.
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

	/** Role map option: role slug => one of role_map_choices() keys. Saved only by an administrator. */
	const OPTION_ROLE_MAP = 'ghca_acd_training_role_map';

	/**
	 * Role map values (55 Pa. Code § 6100.143): key => [label, hours]. "Not required" exists only with its exception.
	 *
	 * @return array<string,array{0:string,1:int}>
	 */
	public static function role_map_choices(): array {
		return array(
			'24'              => array( __( '24 h — § 6100.143(a): direct service professional, life sharer providing an HCBS or base-funding service, or direct supervisor of direct service professionals', 'ghca-acd' ), 24 ),
			'12'              => array( __( '12 h — § 6100.143(b): management, program, administrative, fiscal, dietary, housekeeping, maintenance or ancillary staff; consultant or contractor who works alone with individuals; volunteer or paid/unpaid intern who works alone with individuals', 'ghca-acd' ), 12 ),
			'none_owner'      => array( __( 'Not required — § 6100.143(b)(1) building-owner exception', 'ghca-acd' ), 0 ),
			'none_contractor' => array( __( 'Not required — § 6100.143(b)(2) short-term licensed contractor exception', 'ghca-acd' ), 0 ),
			'none_volunteer'  => array( __( 'Not required — volunteer or intern who never works alone with individuals', 'ghca-acd' ), 0 ),
		);
	}

	/** Basis text shown with the role map. */
	public static function basis_text(): string {
		return __( 'Per 55 Pa. Code §6100.143; nurse mapping is agency policy.', 'ghca-acd' );
	}

	/**
	 * Per-employee override choices (Edit Records): key => [label, hours]. Keys stored by rc.1 are kept.
	 *
	 * @return array<string,array{0:string,1:int}>
	 */
	public static function requirements(): array {
		return array(
			'dsp'             => array( __( 'Direct service professional (24 h)', 'ghca-acd' ), 24 ),
			'life_sharer'     => array( __( 'Life sharer providing an HCBS or base-funding service (24 h)', 'ghca-acd' ), 24 ),
			'dsp_supervisor'  => array( __( 'Direct supervisor of direct service professionals (24 h)', 'ghca-acd' ), 24 ),
			'management'      => array( __( 'Management, program, administrative or fiscal staff (12 h)', 'ghca-acd' ), 12 ),
			'ancillary'       => array( __( 'Dietary, housekeeping, maintenance or ancillary staff (12 h)', 'ghca-acd' ), 12 ),
			'other_12'        => array( __( 'Consultant, contractor, volunteer or intern who works alone with individuals (12 h)', 'ghca-acd' ), 12 ),
			'none_owner'      => array( __( 'Not required — § 6100.143(b)(1) building-owner exception', 'ghca-acd' ), 0 ),
			'none_contractor' => array( __( 'Not required — § 6100.143(b)(2) short-term licensed contractor exception', 'ghca-acd' ), 0 ),
			'none_volunteer'  => array( __( 'Not required — volunteer or intern who never works alone with individuals', 'ghca-acd' ), 0 ),
		);
	}

	/**
	 * The GHCA job roles the map covers: the plugin's active built-in employee roles, the agency's custom roles, and
	 * any other registered role that is not a dashboard/management role (so site roles such as LPN or RN appear).
	 *
	 * @return array<string,string> slug => label
	 */
	public static function role_catalog(): array {
		$roles = array();
		if ( class_exists( 'GHCA_ACD_Roles' ) ) {
			$roles = GHCA_ACD_Roles::active_builtin_employee_roles() + GHCA_ACD_Roles::custom_roles();
			$skip  = array_merge( GHCA_ACD_Roles::dashboard_roles(), array( 'administrator' ) );
			if ( function_exists( 'wp_roles' ) ) {
				foreach ( (array) wp_roles()->role_names as $slug => $name ) {
					if ( ! isset( $roles[ $slug ] ) && ! in_array( $slug, $skip, true ) && ! isset( GHCA_ACD_Roles::builtin_employee_roles()[ $slug ] ) ) {
						$roles[ $slug ] = translate_user_role( (string) $name );
					}
				}
			}
		}
		return array_map( 'strval', $roles );
	}

	/** Nurse roles (LPN, RN, other nurse titles) start at 24 h as agency policy (not ODP text). */
	public static function is_nurse_role( string $slug, string $label = '' ): bool {
		return 1 === preg_match( '/(^|[^a-z])(lpn|rn|nurse|nursing)([^a-z]|$)/i', $slug . ' ' . $label );
	}

	/** @return array<string,string> slug => role map value, as saved (no defaults). */
	public static function role_map(): array {
		$map = function_exists( 'get_option' ) ? get_option( self::OPTION_ROLE_MAP, array() ) : array();
		return self::sanitize_role_map( $map );
	}

	/** @param mixed $value @return array<string,string> */
	public static function sanitize_role_map( $value ): array {
		$out = array();
		if ( ! is_array( $value ) ) {
			return $out;
		}
		foreach ( $value as $slug => $choice ) {
			$slug = is_string( $slug ) ? $slug : '';
			if ( 1 === preg_match( '/^[a-z0-9_-]{1,64}$/', $slug ) && is_string( $choice ) && isset( self::role_map_choices()[ $choice ] ) ) {
				$out[ $slug ] = $choice;
			}
		}
		return $out;
	}

	/**
	 * The effective mapping for one role: the saved value, else 24 for a nurse role (agency policy), else ''.
	 *
	 * @return array{value:string,hours:int,nurse_default:bool}
	 */
	public static function role_value( string $slug, string $label, array $map ): array {
		if ( isset( $map[ $slug ], self::role_map_choices()[ $map[ $slug ] ] ) ) {
			return array( 'value' => $map[ $slug ], 'hours' => (int) self::role_map_choices()[ $map[ $slug ] ][1], 'nurse_default' => false );
		}
		if ( self::is_nurse_role( $slug, $label ) ) {
			return array( 'value' => '24', 'hours' => 24, 'nurse_default' => true );
		}
		return array( 'value' => '', 'hours' => -1, 'nurse_default' => false );
	}

	/**
	 * Pure resolution of one employee's annual requirement.
	 * 1. A valid per-employee override wins (source 'override').
	 * 2. Otherwise the employee's GHCA job roles through the role map; with several roles the highest requirement wins
	 *    (24 > 12 > not required) (source 'role_map').
	 * 3. No mapped role, or an unmapped role that could need more than the mapped ones: 24 h, set=false, flagged
	 *    "Role requirement not set" (source 'default'). An unmapped role never silently becomes 12 h.
	 *
	 * @param array<int,string>    $user_roles Roles the employee holds.
	 * @param array<string,string> $catalog    role_catalog().
	 * @param array<string,string> $map        role_map().
	 * @return array{key:string,label:string,hours:int,set:bool,source:string}
	 */
	public static function resolve( array $user_roles, array $catalog, array $map, string $override = '' ): array {
		$choices = self::requirements();
		if ( isset( $choices[ $override ] ) ) {
			return array( 'key' => $override, 'label' => $choices[ $override ][0], 'hours' => (int) $choices[ $override ][1], 'set' => true, 'source' => 'override' );
		}
		$best = null;
		$unmapped = false;
		foreach ( $user_roles as $slug ) {
			$slug = (string) $slug;
			if ( ! isset( $catalog[ $slug ] ) ) {
				continue;
			}
			$v = self::role_value( $slug, $catalog[ $slug ], $map );
			if ( '' === $v['value'] ) {
				$unmapped = true;
				continue;
			}
			if ( null === $best || $v['hours'] > $best['hours'] ) {
				$best = $v + array( 'slug' => $slug );
			}
		}
		if ( null === $best || ( $unmapped && $best['hours'] < self::DEFAULT_HOURS ) ) {
			return self::default_requirement();
		}
		$role  = $catalog[ $best['slug'] ];
		$hours = (int) $best['hours'];
		if ( $best['nurse_default'] ) {
			$label = sprintf( /* translators: %s: role */ __( '%s: 24 h (agency policy)', 'ghca-acd' ), $role );
		} elseif ( 0 === $hours ) {
			$label = $role . ': ' . self::role_map_choices()[ $best['value'] ][0];
		} else {
			$label = sprintf( /* translators: 1: role, 2: hours, 3: subsection */ __( '%1$s: %2$d h (§ 6100.143%3$s)', 'ghca-acd' ), $role, $hours, 24 === $hours ? '(a)' : '(b)' );
		}
		return array( 'key' => 'role:' . $best['slug'], 'label' => $label, 'hours' => $hours, 'set' => true, 'source' => 'role_map' );
	}

	/** @return array{key:string,label:string,hours:int,set:bool,source:string} */
	public static function default_requirement(): array {
		return array( 'key' => '', 'label' => __( 'Role requirement not set', 'ghca-acd' ), 'hours' => self::DEFAULT_HOURS, 'set' => false, 'source' => 'default' );
	}

	/**
	 * Employee API (stable). The employee's annual requirement and where it came from.
	 *
	 * @return array{key:string,label:string,hours:int,set:bool,source:string}
	 *   hours: 24, 12, or 0 (not required); source: 'override' | 'role_map' | 'default'
	 */
	public static function requirement( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return self::default_requirement();
		}
		$override = function_exists( 'get_user_meta' ) ? (string) get_user_meta( $user_id, self::REQUIREMENT_META, true ) : '';
		$user     = function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : null;
		$roles    = $user && isset( $user->roles ) ? array_values( (array) $user->roles ) : array();
		return self::resolve( $roles, self::role_catalog(), self::role_map(), $override );
	}

	/** Employee API (stable). Annual pass threshold: the resolved hours with the switch on; 24 while it is off. */
	public static function required_hours( int $user_id ): int {
		return self::enabled() ? self::requirement( $user_id )['hours'] : self::DEFAULT_HOURS;
	}

	/** Saves one employee's requirement ('' clears it). Caller has checked nonce, capability and employee scope. */
	public static function save_requirement( int $user_id, string $key ) {
		if ( '' !== $key && ! isset( self::requirements()[ $key ] ) ) {
			return new WP_Error( 'ghca_requirement_invalid', __( 'Invalid annual training requirement.', 'ghca-acd' ) );
		}
		if ( $key === (string) get_user_meta( $user_id, self::REQUIREMENT_META, true ) ) {
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
			return array_merge( $empty, array( 'missing' => 'cycle' ) );
		}
		$window = GHCA_Audit_Calculator::resolve_annual_window( $doh, $cycle, $now ?? time(), wp_timezone() );
		$label  = wp_date( 'M j, Y', (int) $window['start_ts'] ) . ' – ' . wp_date( 'M j, Y', (int) $window['end_ts'] - 1 );
		return array(
			'start_ts'   => (int) $window['start_ts'],
			'end_ts'     => (int) $window['end_ts'],
			'start_date' => (string) $window['start_date'],
			'end_date'   => (string) $window['end_date'],
			'missing'    => '',
			'label'      => $label,
		);
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

	/**
	 * Employee API (stable). One course's due date or expiry for one employee.
	 * - Courses that earn annual ODP credit (is_annual_course()) follow the training year: expiration_ts is
	 *   due()['due_timestamp'], lifespan_days 0, follows_training_year true; the state is computed against that date
	 *   and the warning window (incomplete courses stay 'incomplete' but still carry the date).
	 * - Other courses with a lifespan: calendar-day expiry in the site time zone (as decorate() with the rules on).
	 * - No lifespan: expiration_ts 0 (does not expire).
	 *
	 * @return array{expiration_ts:int,expiration_label:string,compliance_state:string,lifespan_days:int,follows_training_year:bool}
	 */
	public static function course( int $user_id, int $course_id, bool $completed, int $completed_ts, ?int $now = null ): array {
		$now      = $now ?? time();
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$warning  = GHCA_Course_Lifespans::get_warning_days();
		if ( self::is_annual_course( $course_id ) ) {
			$due = (int) self::due( $user_id, $now )['due_timestamp'];
			if ( ! $completed ) {
				$state = 'incomplete';
			} elseif ( $due <= 0 ) {
				$state = 'current';
			} elseif ( $now > $due ) {
				$state = 'expired';
			} elseif ( $now >= ( new DateTimeImmutable( '@' . ( $due + 1 ) ) )->setTimezone( $timezone )->modify( '-' . $warning . ' days' )->getTimestamp() ) {
				$state = 'expiring_soon';
			} else {
				$state = 'current';
			}
			return array(
				'expiration_ts'         => $due,
				'expiration_label'      => $due > 0 ? wp_date( 'M j, Y', $due ) : '',
				'compliance_state'      => $state,
				'lifespan_days'         => 0,
				'follows_training_year' => true,
			);
		}
		$lifespan = GHCA_Course_Lifespans::get_lifespan_days( $course_id );
		$eval     = GHCA_Course_Lifespans::evaluate( $completed, $completed_ts, $lifespan, $warning, $now, $timezone );
		$expires  = (int) $eval['expiration_ts'];
		return array(
			'expiration_ts'         => $expires,
			'expiration_label'      => $expires > 0 ? wp_date( 'M j, Y', $expires ) : '',
			'compliance_state'      => (string) $eval['state'],
			'lifespan_days'         => $lifespan,
			'follows_training_year' => false,
		);
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
