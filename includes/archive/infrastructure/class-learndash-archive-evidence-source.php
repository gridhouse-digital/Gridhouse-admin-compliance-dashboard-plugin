<?php

/** WordPress/LearnDash/dashboard evidence mapping frozen as learndash-local/1.0.0. */
final class GHCA_ACD_LearnDash_Archive_Evidence_Source implements GHCA_ACD_Archive_Evidence_Source {
	public const CALCULATION_POLICY_KEY = 'time-independent';
	public const CALCULATION_POLICY_VERSION = 1;

	private const VERSION_DESCRIPTOR = array(
		'learndash_version' => '5.1.6.1',
		'plugin_version' => '1.2.0',
		'wordpress_version' => '7.0.2',
	);

	/** @var GHCA_ACD_WPDB_Archive_Evidence_Read_Session */
	private $session;
	/** @var array<string,string> */
	private $versions;

	/** @param array<string,mixed> $versions */
	public function __construct( GHCA_ACD_WPDB_Archive_Evidence_Read_Session $session, array $versions ) {
		$actual = array_keys( $versions );
		$expected = array_keys( self::VERSION_DESCRIPTOR );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			$this->unsupported();
		}
		foreach ( self::VERSION_DESCRIPTOR as $key => $value ) {
			if ( ! is_string( $versions[ $key ] ) || $value !== $versions[ $key ] ) {
				$this->unsupported();
			}
		}
		$this->session = $session;
		$this->versions = GHCA_ACD_Archive_Canonical_JSON::detach( $versions );
	}

	/**
	 * @param array<string,mixed> $capture_identity
	 * @param array<string,int> $limits
	 * @return array<string,mixed>
	 */
	public function read_consistent_evidence( array $capture_identity, array $limits, callable $checkpoint ): array {
		$raw = $this->session->read( $capture_identity, $limits, $checkpoint );
		$checkpoint();
		$document = $this->normalize( $raw, $capture_identity, true );
		$checkpoint();
		return ( new GHCA_ACD_Archive_Evidence_Result_Validator() )->validate( $document, $capture_identity );
	}

	/**
	 * @param array<string,mixed> $review_identity
	 * @param array<string,int> $limits
	 * @return array<string,mixed>
	 */
	public function read_consistent_review_evidence( array $review_identity, array $limits, callable $checkpoint ): array {
		$actual = array_keys( $review_identity );
		$expected = array( 'case_key', 'resolved_cycle' );
		sort( $actual, SORT_STRING );
		if ( $actual !== $expected || ! is_array( $review_identity['case_key'] ) || ! is_array( $review_identity['resolved_cycle'] ) ) {
			$this->binding();
		}
		$raw = $this->session->read( $review_identity, $limits, $checkpoint );
		$checkpoint();
		$document = $this->normalize( $raw, $review_identity, false );
		$checkpoint();
		GHCA_ACD_Archive_Canonical_JSON::encode( $document );
		return GHCA_ACD_Archive_Canonical_JSON::detach( $document );
	}

	/** @param array<string,int> $limits */
	public function preflight( array $limits, callable $checkpoint ): void {
		$this->session->preflight_only( $limits, $checkpoint );
	}

	/**
	 * @param array<string,mixed> $raw
	 * @param array<string,mixed> $identity
	 * @return array<string,mixed>
	 */
	private function normalize( array $raw, array $identity, bool $require_policy_digest ): array {
		$this->exact( $raw, array(
			'activities', 'activity_meta', 'certificates', 'configured_group_ids', 'courses',
			'effective_group_ids', 'groups', 'options', 'postmeta', 'query_count', 'user', 'usermeta',
		) );
		$options = $this->option_map( $raw['options'] );
		$role_option_name = $this->option_name( $options );
		$allowed_options = array(
			'blogname', 'ghca_dashboard_brand', 'ghca_acd_audit_mapping',
			'ghca_acd_course_lifespans', 'ghca_acd_warning_days', 'ghca_acd_annual_cycle',
			'ghca_new_hire_group_ids', 'ghca_new_hire_deadline_days',
			'learndash_settings_groups_management_display', $role_option_name,
		);
		if ( array() !== array_diff( array_keys( $options ), $allowed_options ) ) {
			$this->prohibited();
		}
		$audit_source = $this->structured_option( $options, 'ghca_acd_audit_mapping' );
		$lifespan_source = $this->structured_option( $options, 'ghca_acd_course_lifespans' );
		$role_source = $this->structured_option( $options, $role_option_name );
		$mapping = $this->normalize_audit_mapping( $audit_source );
		$program = $identity['case_key']['program_key'] ?? null;
		if ( ! in_array( $program, array( 'annual_training', 'new_hire' ), true ) ) {
			$this->binding();
		}

		$effective_groups = $this->decimal_list( $raw['effective_group_ids'], true );
		$configured_groups = $this->decimal_list( $raw['configured_group_ids'], true );
		$configured_source_groups = $this->decimal_list(
			$this->structured_option( $options, 'ghca_new_hire_group_ids' ),
			true
		);
		if ( array() !== array_diff( $configured_source_groups, $configured_groups ) ) {
			$this->invalid();
		}
		$group_settings = $this->structured_option( $options, 'learndash_settings_groups_management_display' );
		$this->exact( $group_settings, array( 'group_hierarchical_enabled' ) );
		if ( ! in_array( $group_settings['group_hierarchical_enabled'], array( 'yes', 'no' ), true ) ) {
			$this->invalid();
		}
		$postmeta = $this->index_postmeta( $raw['postmeta'] );
		$tracked_ids = array_map( 'strval', array_keys( $mapping ) );
		if ( 'new_hire' === $program ) {
			$tracked_ids = array_values( array_filter(
				$tracked_ids,
				static function ( string $course_id ) use ( $configured_groups, $postmeta ): bool {
					foreach ( $configured_groups as $group_id ) {
						if ( isset( $postmeta[ $course_id ]['learndash_group_enrolled_' . $group_id ] ) ) {
							return true;
						}
					}
					return false;
				}
			) );
		}
		if ( array() === $tracked_ids ) {
			$this->incomplete( 'normalize_limit' );
		}

		$tracked_ids = $this->sorted_decimals( $tracked_ids );
		$display_course_ids = $tracked_ids;
		usort( $display_course_ids, function ( string $left, string $right ) use ( $mapping ): int {
			$category = $mapping[ $left ]['category_order'] <=> $mapping[ $right ]['category_order'];
			if ( 0 !== $category ) {
				return $category;
			}
			$order = $mapping[ $left ]['course_order'] <=> $mapping[ $right ]['course_order'];
			return 0 !== $order ? $order : $this->compare_decimal( $left, $right );
		} );

		$course_rows = $this->index_unique_rows( $raw['courses'], 'ID', 'source_validate' );
		$certificate_rows = $this->index_unique_rows( $raw['certificates'], 'ID', 'certificate_gate' );
		$user_id = $identity['case_key']['employee_user_id_decimal'];
		$activity = $this->index_activities( $raw['activities'], $tracked_ids, $user_id );
		$activity_meta = $this->index_activity_meta( $raw['activity_meta'], $activity );
		$user_meta = $this->index_usermeta( $raw['usermeta'], $tracked_ids, $user_id );
		$cycle = $this->cycle( $identity );
		$warning_days = $this->decimal_scalar_option( $options, 'ghca_acd_warning_days', false );
		$new_hire_days = $this->decimal_scalar_option( $options, 'ghca_new_hire_deadline_days', false );
		$annual_cycle = $this->scalar_option( $options, 'ghca_acd_annual_cycle' );
		if ( ! in_array( $annual_cycle, array( 'calendar_year', 'employee_start_date' ), true ) ) {
			$this->invalid();
		}

		$audit_members = array();
		$lifespan_members = array();
		$course_documents = array();
		$validity = array();
		$course_activity_ids = array();
		$quiz_activity_ids = array();
		foreach ( $display_course_ids as $course_id ) {
			if ( ! isset( $course_rows[ $course_id ] ) ) {
				$this->incomplete( 'source_validate' );
			}
			$audit = $mapping[ $course_id ];
			$lifespan = $this->lifespan( $lifespan_source, $course_id );
			$audit_members[] = array( $course_id, $audit );
			$lifespan_members[] = array( $course_id, array(
				'lifespan_days' => $lifespan,
				'warning_days' => $warning_days,
			) );
			$course = $this->course(
				$course_id,
				$course_rows[ $course_id ],
				$audit,
				$lifespan,
				$effective_groups,
				$postmeta[ $course_id ] ?? array(),
				$user_meta,
				$activity[ $course_id ] ?? array( 'course' => array(), 'quiz' => array() ),
				$activity_meta,
				$certificate_rows,
				$cycle
			);
			$course_documents[] = $course['document'];
			$validity[ $course_id ] = $course['valid'];
			if ( null !== $course['course_activity_id'] ) {
				$course_activity_ids[] = $course['course_activity_id'];
			}
			$quiz_activity_ids = array_merge( $quiz_activity_ids, $course['quiz_activity_ids'] );
		}

		$audit_document = GHCA_ACD_Archive_Canonical_Object::from_members( $audit_members );
		$lifespan_document = GHCA_ACD_Archive_Canonical_Object::from_members( $lifespan_members );
		$quiz_policy = array(
			'attempt_selection' => 'latest_completed',
			'required' => false,
			'score_scale' => 'basis_points',
		);
		$relevant_settings = array(
			'annual_cycle' => $annual_cycle,
			'new_hire_deadline_days' => $new_hire_days,
			'warning_days' => $warning_days,
		);
		$policy_constituent = array(
			'audit_mapping' => $audit_document,
			'completeness_policy' => 'snapshot_v1_complete',
			'course_lifespan_rules' => $lifespan_document,
			'quiz_policy' => $quiz_policy,
			'relevant_settings' => $relevant_settings,
			'tracked_course_ids' => $tracked_ids,
		);
		$policy_digest = hash(
			'sha256',
			"ghca-archive-policy-v1\n" . GHCA_ACD_Archive_Canonical_JSON::encode( $policy_constituent )
		);
		if ( $require_policy_digest && ( ! isset( $identity['policy_digest'] ) || $policy_digest !== $identity['policy_digest'] ) ) {
			$this->binding();
		}

		$user = $this->user( $raw['user'], $identity, $user_meta, $role_source, $effective_groups );
		$site_name = $this->text( $this->scalar_option( $options, 'blogname' ), 255 );
		$agency_name = $site_name;
		if ( isset( $options['ghca_dashboard_brand'] ) ) {
			$brand = $this->decode( $options['ghca_dashboard_brand'] );
			if ( ! is_array( $brand ) ) {
				$this->invalid();
			}
			$this->exact( $brand, array( 'org_name' ), true );
			if ( isset( $brand['org_name'] ) && '' !== trim( (string) $brand['org_name'] ) ) {
				$agency_name = $this->text( $brand['org_name'], 255 );
			}
		}

		$source_ids = array(
			'course_activity_ids' => $this->sorted_decimals( $course_activity_ids ),
			'course_post_ids' => $this->sorted_decimals( $tracked_ids ),
			'group_ids' => $effective_groups,
			'quiz_activity_ids' => $this->sorted_decimals( $quiz_activity_ids ),
			'user_id' => $identity['case_key']['employee_user_id_decimal'],
		);
		$calculated = $this->calculated( $tracked_ids, $mapping, $validity );
		return array(
			'calculated' => $calculated,
			'canonical_format' => 'ghca-cjson-1',
			'case' => array(
				'cycle_key' => $identity['case_key']['cycle_key'],
				'employee_user_id' => $identity['case_key']['employee_user_id_decimal'],
				'program_key' => $identity['case_key']['program_key'],
				'site_id' => $identity['case_key']['site_id_decimal'],
				'tenant_id' => $identity['case_key']['tenant_id'],
			),
			'completeness' => array(
				'missing_fields' => array(),
				'observed_count' => count( $course_documents ),
				'policy_code' => 'snapshot_v1_complete',
				'policy_version' => 1,
				'required_count' => count( $tracked_ids ),
				'result' => 'complete',
				'warnings' => array(),
			),
			'courses' => $course_documents,
			'cycle' => $cycle,
			'organization' => array(
				'agency_name' => $agency_name,
				'site_name' => $site_name,
				'tenant_id' => $identity['case_key']['tenant_id'],
			),
			'policy' => array_merge( $policy_constituent, array( 'policy_digest' => $policy_digest ) ),
			'schema_version' => 1,
			'source' => array(
				'learndash_version' => $this->versions['learndash_version'],
				'plugin_version' => $this->versions['plugin_version'],
				'source_adapter_key' => 'learndash-local',
				'source_adapter_version' => '1.0.0',
				'source_record_ids' => $source_ids,
				'wordpress_version' => $this->versions['wordpress_version'],
			),
			'subject' => $user,
		);
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array<string,mixed> $audit
	 * @param array<int,string> $groups
	 * @param array<string,array<int,array<string,mixed>>> $postmeta
	 * @param array<string,array<int,array<string,mixed>>> $usermeta
	 * @param array<string,array<int,array<string,mixed>>> $activity
	 * @param array<string,array<string,array<int,array<string,mixed>>>> $activity_meta
	 * @param array<string,array<string,mixed>> $certificate_rows
	 * @param array<string,mixed> $cycle
	 * @return array<string,mixed>
	 */
	private function course(
		string $course_id,
		array $row,
		array $audit,
		string $lifespan,
		array $groups,
		array $postmeta,
		array $usermeta,
		array $activity,
		array $activity_meta,
		array $certificate_rows,
		array $cycle
	): array {
		if ( $this->positive_decimal( $row['ID'] ?? null ) !== $course_id
			|| 'sfwd-courses' !== ( $row['post_type'] ?? null )
			|| 'publish' !== ( $row['post_status'] ?? null ) ) {
			$this->invalid();
		}
		$course_rows = $activity['course'];
		if ( count( $course_rows ) > 1 ) {
			$this->invalid();
		}
		$course_activity = array() === $course_rows ? null : $this->activity_row( $course_rows[0] );
		$direct_key = 'course_' . $course_id . '_access_from';
		$completion_key = 'course_completed_' . $course_id;
		$direct_meta = $this->singleton( $usermeta[ $direct_key ] ?? array(), 'source_validate' );
		$completion_meta = $this->singleton( $usermeta[ $completion_key ] ?? array(), 'source_validate' );
		$direct_timestamp = null === $direct_meta ? null : $this->unix( $direct_meta['meta_value'], false );
		$completion_timestamp = null === $completion_meta ? null : $this->unix( $completion_meta['meta_value'], false );

		$started = null;
		$completed = null;
		$status = 'not_started';
		if ( null !== $course_activity ) {
			$started = $this->unix( $course_activity['activity_started'], true );
			$activity_completed = $this->unix( $course_activity['activity_completed'], true );
			$this->within_cycle_or_null( $this->unix( $course_activity['activity_updated'], false ), $cycle );
			$is_complete = $this->strict_truth( $course_activity['activity_status'] );
			if ( $is_complete !== ( null !== $activity_completed ) ) {
				$this->invalid();
			}
			if ( null !== $activity_completed ) {
				if ( null !== $completion_timestamp && $completion_timestamp !== $activity_completed ) {
					$this->invalid();
				}
				$completed = $activity_completed;
				$status = 'completed';
			} elseif ( null !== $started ) {
				$status = 'in_progress';
			}
			if ( null !== $completion_timestamp && null === $activity_completed ) {
				$this->invalid();
			}
		} elseif ( null !== $completion_timestamp ) {
			$this->invalid();
		}
		if ( null === $started ) {
			$started = $direct_timestamp;
			if ( null !== $started && 'not_started' === $status ) {
				$status = 'in_progress';
			}
		}
		$this->within_cycle_or_null( $started, $cycle );
		$this->within_cycle_or_null( $completed, $cycle );
		if ( null !== $completed && null === $started ) {
			$this->incomplete( 'source_validate' );
		}

		$direct = null !== $direct_meta;
		$group_rows = array();
		foreach ( $groups as $group_id ) {
			$key = 'learndash_group_enrolled_' . $group_id;
			$grant = $this->singleton( $postmeta[ $key ] ?? array(), 'source_validate' );
			if ( null !== $grant ) {
				$group_rows[] = $this->postmeta_row( $grant );
			}
		}
		$open_row = $this->singleton( $postmeta['_ld_price_type'] ?? array(), 'source_validate' );
		$open = null !== $open_row && 'open' === $open_row['meta_value'];
		$enrolled = $direct || array() !== $group_rows || $open;
		$selected = $direct ? 'direct' : ( array() !== $group_rows ? 'group' : ( $open ? 'open' : null ) );

		$attempts = array();
		$quiz_rows = array();
		$quiz_meta_rows = array();
		$quiz_ids = array();
		$previous_quiz = null;
		foreach ( $activity['quiz'] as $quiz ) {
			$normalized_quiz = $this->activity_row( $quiz );
			$completed_at = $this->unix( $normalized_quiz['activity_completed'], false );
			$this->within_cycle_or_null( $completed_at, $cycle );
			$this->within_cycle_or_null( $this->unix( $normalized_quiz['activity_started'], false ), $cycle );
			$this->within_cycle_or_null( $this->unix( $normalized_quiz['activity_updated'], false ), $cycle );
			$id = $normalized_quiz['activity_id'];
			$order = array( $normalized_quiz['activity_completed'], $id );
			if ( null !== $previous_quiz && $this->compare_decimal_tuple( $previous_quiz, $order ) >= 0 ) {
				$this->invalid();
			}
			$previous_quiz = $order;
			$pass = $this->singleton( $activity_meta[ $id ]['pass'] ?? array(), 'source_validate' );
			$percentage = $this->singleton( $activity_meta[ $id ]['percentage'] ?? array(), 'source_validate' );
			if ( null === $pass || null === $percentage ) {
				$this->incomplete( 'source_validate' );
			}
			$passed = $this->strict_truth( $pass['activity_meta_value'] );
			if ( $passed !== $this->strict_truth( $normalized_quiz['activity_status'] ) ) {
				$this->invalid();
			}
			$score = $this->basis_points( $percentage['activity_meta_value'] );
			$attempts[] = array(
				'attempt_ordinal' => count( $attempts ),
				'attempted_at_gmt' => $completed_at,
				'passed' => $passed,
				'score_basis_points' => $score,
			);
			$quiz_rows[] = $normalized_quiz;
			$quiz_meta_rows[] = $this->activity_meta_row( $pass );
			$quiz_meta_rows[] = $this->activity_meta_row( $percentage );
			$quiz_ids[] = $id;
		}

		$certificate_assignment = $this->singleton( $postmeta['_ld_certificate'] ?? array(), 'certificate_gate' );
		$certificate_reference = null;
		$certificate_post = null;
		if ( null !== $certificate_assignment && ! in_array( (string) $certificate_assignment['meta_value'], array( '', '0' ), true ) ) {
			try {
				$certificate_id = $this->positive_decimal( $certificate_assignment['meta_value'] );
				if ( ! isset( $certificate_rows[ $certificate_id ] ) ) {
					$this->certificate();
				}
				$certificate_post = $this->post_row( $certificate_rows[ $certificate_id ] );
				if ( 'sfwd-certificates' !== $certificate_post['post_type'] || 'publish' !== $certificate_post['post_status'] ) {
					$this->certificate();
				}
				$certificate_record = array(
					'assignment_meta_id' => $this->positive_decimal( $certificate_assignment['meta_id'] ),
					'course_id' => $course_id,
					'certificate_post_id' => $certificate_id,
					'certificate_post_type' => $certificate_post['post_type'],
					'certificate_post_status' => $certificate_post['post_status'],
					'certificate_post_modified_gmt' => $certificate_post['post_modified_gmt'],
				);
				$certificate_reference = array(
					'certificate_post_id' => $certificate_id,
					'source_record_version' => $this->record_version( 'certificate_reference', $certificate_record ),
				);
			} catch ( GHCA_ACD_Archive_Evidence_Source_Exception $error ) {
				$this->certificate();
			}
		}

		$projection = array(
			'course_post' => $this->post_row( $row ),
			'audit_mapping' => $audit,
			'lifespan_days' => $lifespan,
			'enrollment_sources' => array(
				'direct_access_meta' => null === $direct_meta ? null : $this->usermeta_row( $direct_meta ),
				'group_grant_meta' => $group_rows,
				'open_price_meta' => $open ? $this->postmeta_row( $open_row ) : null,
				'selected' => $selected,
			),
			'course_activity' => $course_activity,
			'completion_meta' => null === $completion_meta ? null : $this->usermeta_row( $completion_meta ),
			'quiz_activities' => $quiz_rows,
			'quiz_meta' => $quiz_meta_rows,
			'certificate_assignment' => null === $certificate_assignment ? null : $this->postmeta_row( $certificate_assignment ),
			'certificate_post' => $certificate_post,
		);
		$record_id = null === $course_activity ? $course_id : $course_activity['activity_id'];
		$latest_score = array() === $attempts ? null : $attempts[ count( $attempts ) - 1 ]['score_basis_points'];
		$valid = $enrolled && 'completed' === $status;
		return array(
			'document' => array(
				'category_order' => $audit['category_order'],
				'certificate_reference' => $certificate_reference,
				'certificate_required' => null !== $certificate_reference,
				'completed_at_gmt' => $completed,
				'completion_status' => $status,
				'course_id' => $course_id,
				'course_order' => $audit['course_order'],
				'course_stable_key' => null,
				'course_title' => $this->text( $row['post_title'] ?? null, 255 ),
				'enrollment_status' => $enrolled ? 'enrolled' : 'not_enrolled',
				'pass_state' => 'not_applicable',
				'quiz_attempts' => $attempts,
				'quiz_score_basis_points' => $latest_score,
				'source_provenance' => array(
					'adapter_key' => 'learndash-local',
					'record_id' => $record_id,
					'record_version' => $this->record_version( 'course_evidence', $projection ),
				),
				'started_at_gmt' => $started,
				'time_spent_seconds' => '0',
			),
			'valid' => $valid,
			'course_activity_id' => null === $course_activity ? null : $course_activity['activity_id'],
			'quiz_activity_ids' => $quiz_ids,
		);
	}

	/**
	 * @param array<int,string> $ids
	 * @param array<string,array<string,mixed>> $mapping
	 * @param array<string,bool> $validity
	 * @return array<string,mixed>
	 */
	private function calculated( array $ids, array $mapping, array $validity ): array {
		$category_ids = array();
		$category_minutes = array();
		$matrix = array( 'odp' => array(), 'oltl' => array() );
		foreach ( $ids as $course_id ) {
			$audit = $mapping[ $course_id ];
			foreach ( array( 'odp' => 'odp_category_key', 'oltl' => 'oltl_category_key' ) as $kind => $field ) {
				$key = $audit[ $field ];
				if ( null !== $key ) {
					if ( ! isset( $matrix[ $kind ][ $key ] ) ) {
						$matrix[ $kind ][ $key ] = true;
					}
					$matrix[ $kind ][ $key ] = $matrix[ $kind ][ $key ] && $validity[ $course_id ];
				}
			}
			$key = $audit['odp_category_key'];
			if ( null !== $key ) {
				if ( ! isset( $category_ids[ $key ] ) ) {
					$category_ids[ $key ] = array();
					$category_minutes[ $key ] = '0';
				}
				if ( $validity[ $course_id ] ) {
					$category_ids[ $key ][] = $course_id;
					$category_minutes[ $key ] = $this->add_decimal( $category_minutes[ $key ], $audit['credit_minutes'] );
				}
			}
		}
		$category_members = array();
		$odp_members = array();
		$oltl_members = array();
		$category_keys = array_keys( $category_ids );
		sort( $category_keys, SORT_STRING );
		foreach ( $category_keys as $key ) {
			$category_members[] = array( $key, array(
				'completed_course_ids' => $this->sorted_decimals( $category_ids[ $key ] ),
				'credit_minutes' => $category_minutes[ $key ],
			) );
		}
		foreach ( array( 'odp' => &$odp_members, 'oltl' => &$oltl_members ) as $kind => &$members ) {
			$keys = array_keys( $matrix[ $kind ] );
			sort( $keys, SORT_STRING );
			foreach ( $keys as $key ) {
				$members[] = array( $key, $matrix[ $kind ][ $key ] );
			}
		}
		unset( $members );
		return array(
			'calculation_version' => self::CALCULATION_POLICY_VERSION,
			'categories' => array() === $category_members
				? GHCA_ACD_Archive_Empty_Object::instance()
				: GHCA_ACD_Archive_Canonical_Object::from_members( $category_members ),
			'compliance_status' => ! in_array( false, $validity, true ) ? 'compliant' : 'non_compliant',
			'exceptions' => array(),
			'matrix' => array(
				'odp' => array() === $odp_members ? GHCA_ACD_Archive_Empty_Object::instance() : GHCA_ACD_Archive_Canonical_Object::from_members( $odp_members ),
				'oltl' => array() === $oltl_members ? GHCA_ACD_Archive_Empty_Object::instance() : GHCA_ACD_Archive_Canonical_Object::from_members( $oltl_members ),
			),
			'total_course_count' => count( $ids ),
			'total_training_seconds' => '0',
		);
	}

	/** @param array<int,array<string,mixed>> $rows @return array<string,mixed> */
	private function option_map( array $rows ): array {
		$map = array();
		foreach ( $rows as $row ) {
			$this->exact( $row, array( 'option_id', 'option_name', 'option_value' ) );
			$name = $row['option_name'];
			if ( ! is_string( $name ) || isset( $map[ $name ] ) ) {
				$this->invalid();
			}
			$map[ $name ] = $row['option_value'];
		}
		return $map;
	}

	/** @param array<string,mixed> $options */
	private function option_name( array $options ): string {
		$matches = array();
		foreach ( array_keys( $options ) as $name ) {
			if ( is_string( $name ) && preg_match( '/^[A-Za-z_][A-Za-z0-9_]*user_roles$/D', $name ) ) {
				$matches[] = $name;
			}
		}
		if ( 1 !== count( $matches ) ) {
			$this->invalid();
		}
		return $matches[0];
	}

	/** @param array<string,mixed> $options @return array<mixed> */
	private function structured_option( array $options, string $name ): array {
		if ( ! array_key_exists( $name, $options ) ) {
			$this->incomplete( 'source_validate' );
		}
		$value = $this->decode( $options[ $name ] );
		if ( ! is_array( $value ) ) {
			$this->invalid();
		}
		return $value;
	}

	/** @param array<string,mixed> $options */
	private function scalar_option( array $options, string $name ): string {
		if ( ! array_key_exists( $name, $options ) ) {
			$this->incomplete( 'source_validate' );
		}
		$value = $this->decode( $options[ $name ] );
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			$this->invalid();
		}
		return (string) $value;
	}

	/** @param array<string,mixed> $options */
	private function decimal_scalar_option( array $options, string $name, bool $positive ): string {
		$value = $this->scalar_option( $options, $name );
		return $positive ? $this->positive_decimal( $value ) : $this->decimal( $value );
	}

	/** @param array<mixed> $source @return array<string,array<string,mixed>> */
	private function normalize_audit_mapping( array $source ): array {
		$output = array();
		foreach ( $source as $course_id => $value ) {
			$id = $this->positive_decimal( $course_id );
			if ( isset( $output[ $id ] ) || ! is_array( $value ) ) {
				$this->invalid();
			}
			$this->exact( $value, array( 'credit_hours', 'is_orientation', 'odp_category', 'oltl_category', 'sort_order' ) );
			$course_order = $value['sort_order'];
			if ( is_string( $course_order ) && preg_match( '/^(?:0|[1-9][0-9]*)$/D', $course_order ) ) {
				$course_order = (int) $course_order;
			}
			if ( ! is_int( $course_order ) || $course_order < 0 || ! in_array( $value['is_orientation'], array( 0, 1, '0', '1', false, true ), true ) ) {
				$this->invalid();
			}
			$output[ $id ] = array(
				'category_order' => 0,
				'course_order' => $course_order,
				'credit_minutes' => $this->credit_minutes( $value['credit_hours'] ),
				'is_orientation' => in_array( $value['is_orientation'], array( 1, '1', true ), true ),
				'odp_category_key' => $this->nullable_key( $value['odp_category'] ),
				'oltl_category_key' => $this->nullable_key( $value['oltl_category'] ),
			);
		}
		if ( array() === $output ) {
			$this->incomplete( 'normalize_limit' );
		}
		return $output;
	}

	/** @param array<mixed> $source */
	private function lifespan( array $source, string $course_id ): string {
		foreach ( $source as $key => $value ) {
			if ( $this->positive_decimal( $key ) === $course_id ) {
				return $this->decimal( $value );
			}
		}
		$this->incomplete( 'source_validate' );
	}

	/** @param mixed $value */
	private function credit_minutes( $value ): string {
		if ( is_int( $value ) ) {
			if ( $value < 0 ) {
				$this->invalid();
			}
			return (string) ( $value * 60 );
		}
		if ( is_float( $value ) ) {
			if ( ! is_finite( $value ) ) {
				$this->invalid();
			}
			$value = sprintf( '%.6F', $value );
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(0|[1-9][0-9]*)(?:\\.([0-9]{1,6}))?$/D', $value, $match ) ) {
			$this->invalid();
		}
		$fraction = str_pad( $match[2] ?? '', 6, '0' );
		$quarter_minutes = array(
			'000000' => '0',
			'250000' => '15',
			'500000' => '30',
			'750000' => '45',
		);
		if ( ! isset( $quarter_minutes[ $fraction ] ) ) {
			$this->invalid();
		}
		return $this->add_decimal( $this->multiply_decimal( $match[1], 60 ), $quarter_minutes[ $fraction ] );
	}

	/** @param array<int,array<string,mixed>> $rows @return array<string,array<int,array<string,mixed>>> */
	private function index_postmeta( array $rows ): array {
		$output = array();
		foreach ( $rows as $row ) {
			try {
				$this->exact( $row, array( 'meta_id', 'meta_key', 'meta_value', 'post_id' ) );
				$post_id = $this->positive_decimal( $row['post_id'] );
				if ( ! is_string( $row['meta_key'] ) ) {
					$this->invalid();
				}
			} catch ( GHCA_ACD_Archive_Evidence_Source_Exception $error ) {
				if ( '_ld_certificate' === ( $row['meta_key'] ?? null ) ) {
					$this->certificate();
				}
				throw $error;
			}
			$output[ $post_id ][ $row['meta_key'] ][] = $row;
		}
		return $output;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @param array<int,string> $course_ids
	 * @return array<string,array<string,array<int,array<string,mixed>>>>
	 */
	private function index_activities( array $rows, array $course_ids, string $user_id ): array {
		$output = array();
		foreach ( $rows as $row ) {
			$this->exact( $row, array(
				'activity_completed', 'activity_id', 'activity_started', 'activity_status', 'activity_type',
				'activity_updated', 'course_id', 'post_id', 'user_id',
			) );
			$course_id = $this->positive_decimal( $row['course_id'] );
			if ( ! in_array( $course_id, $course_ids, true ) || ! in_array( $row['activity_type'], array( 'course', 'quiz' ), true ) ) {
				$this->invalid();
			}
			if ( $this->positive_decimal( $row['user_id'] ) !== $user_id
				|| ( 'course' === $row['activity_type'] && $this->positive_decimal( $row['post_id'] ) !== $course_id ) ) {
				$this->invalid();
			}
			$output[ $course_id ][ $row['activity_type'] ][] = $row;
		}
		foreach ( $course_ids as $course_id ) {
			$output[ $course_id ] = $output[ $course_id ] ?? array();
			$output[ $course_id ]['course'] = $output[ $course_id ]['course'] ?? array();
			$output[ $course_id ]['quiz'] = $output[ $course_id ]['quiz'] ?? array();
		}
		return $output;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @param array<string,array<string,array<int,array<string,mixed>>>> $activities
	 * @return array<string,array<string,array<int,array<string,mixed>>>>
	 */
	private function index_activity_meta( array $rows, array $activities ): array {
		$quiz_ids = array();
		foreach ( $activities as $by_type ) {
			foreach ( $by_type['quiz'] as $quiz ) {
				$quiz_ids[] = $this->positive_decimal( $quiz['activity_id'] );
			}
		}
		$output = array();
		foreach ( $rows as $row ) {
			$this->exact( $row, array( 'activity_id', 'activity_meta_id', 'activity_meta_key', 'activity_meta_value' ) );
			$id = $this->positive_decimal( $row['activity_id'] );
			if ( ! in_array( $id, $quiz_ids, true )
				|| ! in_array( $row['activity_meta_key'], array( 'pass', 'percentage' ), true ) ) {
				$this->invalid();
			}
			$output[ $id ][ $row['activity_meta_key'] ][] = $row;
		}
		return $output;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @param array<int,string> $course_ids
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function index_usermeta( array $rows, array $course_ids, string $user_id ): array {
		$output = array();
		foreach ( $rows as $row ) {
			$this->exact( $row, array( 'meta_key', 'meta_value', 'umeta_id', 'user_id' ) );
			$key = $row['meta_key'];
			if ( ! is_string( $key ) ) {
				$this->invalid();
			}
			if ( $this->positive_decimal( $row['user_id'] ) !== $user_id ) {
				$this->invalid();
			}
			$allowed = in_array( $key, array( 'first_name', 'last_name' ), true )
				|| 1 === preg_match( '/^[A-Za-z_][A-Za-z0-9_]*capabilities$/D', $key )
				|| 1 === preg_match( '/^learndash_group_users_[1-9][0-9]*$/D', $key );
			foreach ( $course_ids as $course_id ) {
				$allowed = $allowed || in_array( $key, array(
					'course_' . $course_id . '_access_from',
					'course_completed_' . $course_id,
				), true );
			}
			if ( ! $allowed ) {
				$this->prohibited();
			}
			if ( preg_match( '/^learndash_group_users_([1-9][0-9]*)$/D', $key, $match )
				&& (string) $row['meta_value'] !== $match[1] ) {
				$this->invalid();
			}
			$output[ $key ][] = $row;
		}
		return $output;
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array<string,mixed> $identity
	 * @param array<string,array<int,array<string,mixed>>> $meta
	 * @param array<mixed> $roles
	 * @param array<int,string> $groups
	 * @return array<string,mixed>
	 */
	private function user( array $row, array $identity, array $meta, array $roles, array $groups ): array {
		$this->exact( $row, array( 'ID', 'display_name', 'user_email', 'user_registered' ) );
		$id = $this->positive_decimal( $row['ID'] );
		if ( $id !== $identity['case_key']['employee_user_id_decimal'] ) {
			$this->binding();
		}
		$first = $this->singleton( $meta['first_name'] ?? array(), 'source_validate' );
		$last = $this->singleton( $meta['last_name'] ?? array(), 'source_validate' );
		$display = trim( (string) ( $first['meta_value'] ?? '' ) . ' ' . (string) ( $last['meta_value'] ?? '' ) );
		if ( '' === $display ) {
			$display = $row['display_name'];
		}
		$capabilities = array();
		foreach ( $meta as $key => $values ) {
			if ( preg_match( '/capabilities$/D', $key ) ) {
				$capabilities = $this->decode_single_structured( $values );
			}
		}
		$role_keys = array();
		foreach ( $capabilities as $key => $enabled ) {
			if ( is_string( $key ) && true === $enabled && array_key_exists( $key, $roles ) ) {
				$role_keys[] = $this->key( $key );
			}
		}
		sort( $role_keys, SORT_STRING );
		return array(
			'display_name' => $this->text( $display, 255 ),
			'email' => strtolower( $this->text( $row['user_email'], 254 ) ),
			'employee_user_id' => $id,
			'external_employee_key' => null,
			'group_ids' => $groups,
			'registered_at_gmt' => $this->database_gmt( $row['user_registered'] ),
			'role_keys' => $role_keys,
		);
	}

	/** @param array<int,array<string,mixed>> $rows @return array<mixed> */
	private function decode_single_structured( array $rows ): array {
		$row = $this->singleton( $rows, 'source_validate' );
		if ( null === $row ) {
			$this->incomplete( 'source_validate' );
		}
		$value = $this->decode( $row['meta_value'] );
		if ( ! is_array( $value ) ) {
			$this->invalid();
		}
		return $value;
	}

	/** @param array<int,array<string,mixed>> $rows @return array<string,mixed>|null */
	private function singleton( array $rows, string $context ): ?array {
		if ( count( $rows ) > 1 ) {
			if ( 'certificate_gate' === $context ) {
				$this->certificate();
			}
			$this->invalid();
		}
		return array() === $rows ? null : $rows[0];
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<string,array<string,mixed>>
	 */
	private function index_unique_rows( array $rows, string $field, string $context ): array {
		$output = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! array_key_exists( $field, $row ) ) {
				if ( 'certificate_gate' === $context ) {
					$this->certificate();
				}
				$this->incomplete( $context );
			}
			try {
				$id = $this->positive_decimal( $row[ $field ] );
			} catch ( GHCA_ACD_Archive_Evidence_Source_Exception $error ) {
				if ( 'certificate_gate' === $context ) {
					$this->certificate();
				}
				throw $error;
			}
			if ( isset( $output[ $id ] ) ) {
				if ( 'certificate_gate' === $context ) {
					$this->certificate();
				}
				$this->invalid();
			}
			$output[ $id ] = $row;
		}
		return $output;
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function post_row( array $row ): array {
		$this->exact( $row, array( 'ID', 'post_modified_gmt', 'post_parent', 'post_status', 'post_title', 'post_type' ) );
		return array(
			'ID' => $this->positive_decimal( $row['ID'] ),
			'post_modified_gmt' => $this->database_gmt( $row['post_modified_gmt'] ),
			'post_parent' => $this->decimal( $row['post_parent'] ),
			'post_status' => $this->text( $row['post_status'], 32 ),
			'post_title' => $this->text( $row['post_title'], 255 ),
			'post_type' => $this->text( $row['post_type'], 32 ),
		);
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function activity_row( array $row ): array {
		return array(
			'activity_completed' => $this->decimal( $row['activity_completed'] ),
			'activity_id' => $this->positive_decimal( $row['activity_id'] ),
			'activity_started' => $this->decimal( $row['activity_started'] ),
			'activity_status' => $this->truth_decimal( $row['activity_status'] ),
			'activity_type' => $row['activity_type'],
			'activity_updated' => $this->decimal( $row['activity_updated'] ),
			'course_id' => $this->positive_decimal( $row['course_id'] ),
			'post_id' => $this->positive_decimal( $row['post_id'] ),
			'user_id' => $this->positive_decimal( $row['user_id'] ),
		);
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function usermeta_row( array $row ): array {
		return array(
			'meta_key' => $row['meta_key'],
			'meta_value' => (string) $row['meta_value'],
			'umeta_id' => $this->positive_decimal( $row['umeta_id'] ),
			'user_id' => $this->positive_decimal( $row['user_id'] ),
		);
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function postmeta_row( array $row ): array {
		return array(
			'meta_id' => $this->positive_decimal( $row['meta_id'] ),
			'meta_key' => $row['meta_key'],
			'meta_value' => (string) $row['meta_value'],
			'post_id' => $this->positive_decimal( $row['post_id'] ),
		);
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function activity_meta_row( array $row ): array {
		return array(
			'activity_id' => $this->positive_decimal( $row['activity_id'] ),
			'activity_meta_id' => $this->positive_decimal( $row['activity_meta_id'] ),
			'activity_meta_key' => $row['activity_meta_key'],
			'activity_meta_value' => (string) $row['activity_meta_value'],
		);
	}

	/** @param mixed $value @return mixed */
	private function decode( $value ) {
		if ( ! is_string( $value ) ) {
			$this->invalid();
		}
		if ( 1 !== preg_match( '/^(?:a|b|d|i|s|N|O|C|R|r):/', $value ) ) {
			return $value;
		}
		$decoded = @unserialize( $value, array( 'allowed_classes' => false ) );
		if ( false === $decoded && 'b:0;' !== $value ) {
			$this->invalid();
		}
		$values = 0;
		$this->scalar_tree( $decoded, 0, $values );
		return $decoded;
	}

	/** @param mixed $value */
	private function scalar_tree( $value, int $depth, int &$values ): void {
		if ( $depth > GHCA_ACD_Archive_Canonical_JSON::MAX_DEPTH || ++$values > GHCA_ACD_Archive_Canonical_JSON::MAX_VALUES ) {
			$this->incomplete( 'normalize_limit' );
		}
		if ( is_string( $value ) && strlen( $value ) > GHCA_ACD_Archive_Canonical_JSON::MAX_STRING_BYTES ) {
			$this->incomplete( 'normalize_limit' );
		}
		if ( is_object( $value ) || is_resource( $value ) || is_float( $value ) && ! is_finite( $value ) ) {
			$this->prohibited();
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				if ( ! is_int( $key ) && ! is_string( $key ) ) {
					$this->prohibited();
				}
				if ( ReflectionReference::fromArrayElement( $value, $key ) instanceof ReflectionReference ) {
					$this->prohibited();
				}
				if ( is_string( $key ) && strlen( $key ) > GHCA_ACD_Archive_Canonical_JSON::MAX_STRING_BYTES ) {
					$this->incomplete( 'normalize_limit' );
				}
				$this->scalar_tree( $item, $depth + 1, $values );
			}
		}
	}

	/** @param array<string,mixed> $identity @return array<string,mixed> */
	private function cycle( array $identity ): array {
		if ( ! isset( $identity['resolved_cycle'] ) || ! is_array( $identity['resolved_cycle'] ) ) {
			$this->binding();
		}
		return GHCA_ACD_Archive_Canonical_JSON::detach( $identity['resolved_cycle'] );
	}

	/** @param mixed $value */
	private function database_gmt( $value ): string {
		if ( ! is_string( $value ) ) {
			$this->invalid();
		}
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		$errors = DateTimeImmutable::getLastErrors();
		if ( false === $date || is_array( $errors ) && ( 0 !== $errors['warning_count'] || 0 !== $errors['error_count'] )
			|| $date->format( 'Y-m-d H:i:s' ) !== $value ) {
			$this->invalid();
		}
		return $date->format( 'Y-m-d\\TH:i:s\\Z' );
	}

	/** @param mixed $value */
	private function unix( $value, bool $nullable_zero ): ?string {
		$decimal = $this->decimal( $value );
		if ( '0' === $decimal && $nullable_zero ) {
			return null;
		}
		if ( '0' === $decimal ) {
			$this->invalid();
		}
		$timestamp = (int) $decimal;
		if ( (string) $timestamp !== $decimal || $timestamp < 0 ) {
			$this->invalid();
		}
		return gmdate( 'Y-m-d\\TH:i:s\\Z', $timestamp );
	}

	/** @param array<string,mixed> $cycle */
	private function within_cycle_or_null( ?string $value, array $cycle ): void {
		if ( null !== $value && ( strcmp( $value, $cycle['start_gmt'] ) < 0 || strcmp( $value, $cycle['end_gmt'] ) >= 0 ) ) {
			$this->invalid();
		}
	}

	/** @param mixed $value */
	private function basis_points( $value ): int {
		$value = (string) $value;
		if ( 1 !== preg_match( '/^(?:0|[1-9][0-9]?|100)(?:\\.([0-9]{1,6}))?$/D', $value, $match ) ) {
			$this->invalid();
		}
		$parts = explode( '.', $value, 2 );
		$whole = (int) $parts[0];
		$fraction = str_pad( $parts[1] ?? '', 6, '0' );
		if ( 100 === $whole && '000000' !== $fraction ) {
			$this->invalid();
		}
		$basis = $whole * 100 + (int) substr( $fraction, 0, 2 );
		if ( (int) $fraction[2] >= 5 ) {
			$basis++;
		}
		return $basis;
	}

	/** @param mixed $value */
	private function strict_truth( $value ): bool {
		if ( in_array( $value, array( true, 1, '1' ), true ) ) {
			return true;
		}
		if ( in_array( $value, array( false, 0, '0' ), true ) ) {
			return false;
		}
		$this->invalid();
	}

	/** @param mixed $value */
	private function truth_decimal( $value ): string {
		return $this->strict_truth( $value ) ? '1' : '0';
	}

	/** @param mixed $value */
	private function nullable_key( $value ): ?string {
		if ( ! is_string( $value ) ) {
			$this->invalid();
		}
		return '' === $value ? null : $this->key( $value );
	}

	private function key( string $value ): string {
		if ( strlen( $value ) > 64 || 1 !== preg_match( '/^[a-z][a-z0-9_]*$/D', $value ) ) {
			$this->invalid();
		}
		return $value;
	}

	/** @param mixed $value */
	private function text( $value, int $maximum ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) || trim( $value ) !== $value || strlen( $value ) > $maximum ) {
			$this->invalid();
		}
		return $value;
	}

	/** @param mixed $value */
	private function decimal( $value ): string {
		$value = is_int( $value ) ? (string) $value : $value;
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			$this->invalid();
		}
		return $value;
	}

	/** @param mixed $value */
	private function positive_decimal( $value ): string {
		$value = $this->decimal( $value );
		if ( '0' === $value ) {
			$this->invalid();
		}
		return $value;
	}

	/** @param mixed $value @return array<int,string> */
	private function decimal_list( $value, bool $allow_empty ): array {
		if ( ! is_array( $value ) || ! $allow_empty && array() === $value ) {
			$this->invalid();
		}
		$ids = array();
		foreach ( $value as $item ) {
			$ids[] = $this->positive_decimal( $item );
		}
		return $this->sorted_decimals( $ids );
	}

	/** @param array<int,string> $values @return array<int,string> */
	private function sorted_decimals( array $values ): array {
		$values = array_values( array_unique( $values ) );
		usort( $values, array( $this, 'compare_decimal' ) );
		return $values;
	}

	private function compare_decimal( string $left, string $right ): int {
		$length = strlen( $left ) <=> strlen( $right );
		return 0 !== $length ? $length : strcmp( $left, $right );
	}

	/** @param array{0:string,1:string} $left @param array{0:string,1:string} $right */
	private function compare_decimal_tuple( array $left, array $right ): int {
		$first = $this->compare_decimal( $left[0], $right[0] );
		return 0 !== $first ? $first : $this->compare_decimal( $left[1], $right[1] );
	}

	private function add_decimal( string $left, string $right ): string {
		$carry = 0;
		$output = '';
		for ( $li = strlen( $left ) - 1, $ri = strlen( $right ) - 1; $li >= 0 || $ri >= 0 || $carry; $li--, $ri-- ) {
			$sum = ( $li >= 0 ? ord( $left[ $li ] ) - 48 : 0 ) + ( $ri >= 0 ? ord( $right[ $ri ] ) - 48 : 0 ) + $carry;
			$output = (string) ( $sum % 10 ) . $output;
			$carry = intdiv( $sum, 10 );
		}
		return ltrim( $output, '0' ) ?: '0';
	}

	private function multiply_decimal( string $value, int $multiplier ): string {
		$output = '0';
		for ( $index = 0; $index < $multiplier; $index++ ) {
			$output = $this->add_decimal( $output, $value );
		}
		return $output;
	}

	/** @param mixed $record */
	private function record_version( string $kind, $record ): string {
		return hash( 'sha256', "ghca-source-record-version-v1\n" . GHCA_ACD_Archive_Canonical_JSON::encode( array(
			'adapter_key' => 'learndash-local',
			'adapter_version' => '1.0.0',
			'kind' => $kind,
			'record' => $record,
		) ) );
	}

	/**
	 * @param array<string,mixed> $actual
	 * @param array<int,string> $expected
	 */
	private function exact( array $actual, array $expected, bool $allow_missing = false ): void {
		$keys = array_keys( $actual );
		$extra = array_diff( $keys, $expected );
		if ( array() !== $extra ) {
			$this->prohibited();
		}
		if ( ! $allow_missing && array() !== array_diff( $expected, $keys ) ) {
			$this->incomplete( 'source_validate' );
		}
	}

	private function unsupported(): void {
		throw new GHCA_ACD_Archive_Evidence_Source_Exception(
			GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_OPERATIONAL_BLOCKED,
			'archive_source_schema_unsupported',
			'source_preflight'
		);
	}

	private function binding(): void {
		throw new GHCA_ACD_Archive_Evidence_Source_Exception(
			GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_INVALID,
			'archive_build_binding_invalid',
			'authoritative_load'
		);
	}

	private function invalid(): void {
		throw new GHCA_ACD_Archive_Evidence_Source_Exception(
			GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_INVALID,
			'archive_snapshot_invalid',
			'source_validate'
		);
	}

	private function prohibited(): void {
		throw new GHCA_ACD_Archive_Evidence_Source_Exception(
			GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_INVALID,
			'archive_evidence_prohibited',
			'source_validate'
		);
	}

	private function incomplete( string $context ): void {
		throw new GHCA_ACD_Archive_Evidence_Source_Exception(
			GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_INVALID,
			'archive_evidence_incomplete',
			$context
		);
	}

	private function certificate(): void {
		throw new GHCA_ACD_Archive_Evidence_Source_Exception(
			GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_INVALID,
			'archive_certificate_invalid',
			'certificate_gate'
		);
	}
}
