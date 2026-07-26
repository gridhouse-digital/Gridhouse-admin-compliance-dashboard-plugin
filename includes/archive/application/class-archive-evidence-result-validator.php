<?php

/** Exact validator for normalized-evidence-document-v1. */
final class GHCA_ACD_Archive_Evidence_Result_Validator {
	/** @param array<string,mixed> $document @param array<string,mixed> $identity @return array<string,mixed> */
	public function validate( array $document, array $identity ): array {
		$this->exact( $document, array(
			'calculated', 'canonical_format', 'case', 'completeness', 'courses', 'cycle',
			'organization', 'policy', 'schema_version', 'source', 'subject',
		) );
		if ( 1 !== $document['schema_version'] || 'ghca-cjson-1' !== $document['canonical_format'] ) {
			$this->invalid();
		}
		$this->assert_no_prohibited( $document );
		if ( ! is_array( $document['courses'] ) || ! $this->is_list( $document['courses'] ) || count( $document['courses'] ) > 10000 ) {
			$this->incomplete( 'normalize_limit' );
		}
		try {
			GHCA_ACD_Archive_Canonical_JSON::encode( $document );
		} catch ( InvalidArgumentException $error ) {
			if ( false !== strpos( $error->getMessage(), 'value-count' ) || false !== strpos( $error->getMessage(), 'byte limit' ) ) {
				$this->incomplete( 'normalize_limit' );
			}
			$this->prohibited();
		}
		$this->validate_identity( $identity );
		$this->validate_case( $document['case'], $identity );
		$this->validate_cycle( $document['cycle'], $identity['resolved_cycle'] );
		$this->validate_organization( $document['organization'], $document['case'] );
		$this->validate_subject( $document['subject'], $document['case'] );
		$this->validate_policy( $document['policy'], $identity );
		$this->validate_source( $document['source'], $document['case'] );
		$this->validate_courses( $document['courses'], $document['policy'], $document['cycle'] );
		$this->validate_completeness( $document['completeness'], $document['courses'], $document['policy'] );
		$this->validate_calculated( $document['calculated'], $document['courses'] );
		return GHCA_ACD_Archive_Canonical_JSON::detach( $document );
	}

	/** @param array<string,mixed> $identity */
	private function validate_identity( array $identity ): void {
		$this->exact( $identity, array(
			'archive_id', 'case_key', 'policy_digest', 'resolved_cycle', 'reviewed_source_fingerprint',
			'revision_number', 'stream_id', 'subject_scope_digest', 'trigger_event_id',
		) );
		foreach ( array( 'archive_id', 'stream_id', 'trigger_event_id' ) as $field ) {
			if ( ! $this->id( $identity[ $field ] ) ) { $this->invalid(); }
		}
		foreach ( array( 'policy_digest', 'reviewed_source_fingerprint', 'subject_scope_digest' ) as $field ) {
			if ( ! $this->digest( $identity[ $field ] ) ) { $this->invalid(); }
		}
		if ( ! is_int( $identity['revision_number'] ) || $identity['revision_number'] < 1 || ! is_array( $identity['case_key'] ) || ! is_array( $identity['resolved_cycle'] ) ) {
			$this->invalid();
		}
	}

	/** @param mixed $case @param array<string,mixed> $identity */
	private function validate_case( $case, array $identity ): void {
		if ( ! is_array( $case ) ) { $this->invalid(); }
		$this->exact( $case, array( 'cycle_key', 'employee_user_id', 'program_key', 'site_id', 'tenant_id' ) );
		$key = $identity['case_key'];
		if ( ! $this->id( $case['tenant_id'] ) || ! $this->positive_decimal( $case['site_id'] )
			|| ! $this->positive_decimal( $case['employee_user_id'] ) || ! $this->machine_key( $case['program_key'], 64 )
			|| ! $this->text( $case['cycle_key'], 191 )
			|| $case['tenant_id'] !== $key['tenant_id'] || $case['site_id'] !== $key['site_id_decimal']
			|| $case['employee_user_id'] !== $key['employee_user_id_decimal']
			|| $case['program_key'] !== $key['program_key'] || $case['cycle_key'] !== $key['cycle_key'] ) {
			$this->invalid();
		}
	}

	/** @param mixed $cycle @param array<string,mixed> $authoritative */
	private function validate_cycle( $cycle, array $authoritative ): void {
		if ( ! is_array( $cycle ) ) { $this->invalid(); }
		$this->exact( $cycle, array( 'boundary', 'display_label', 'end_gmt', 'key', 'policy_key', 'policy_version', 'start_gmt', 'timezone' ) );
		if ( $cycle !== $authoritative || '[)' !== $cycle['boundary'] || ! $this->text( $cycle['display_label'], 191 )
			|| ! $this->text( $cycle['key'], 191 ) || ! $this->machine_key( $cycle['policy_key'], 64 )
			|| ! is_int( $cycle['policy_version'] ) || $cycle['policy_version'] < 1
			|| ! $this->utc( $cycle['start_gmt'] ) || ! $this->utc( $cycle['end_gmt'] )
			|| strcmp( $cycle['start_gmt'], $cycle['end_gmt'] ) >= 0 || ! $this->timezone( $cycle['timezone'] ) ) {
			$this->invalid();
		}
	}

	/** @param mixed $organization @param array<string,mixed> $case */
	private function validate_organization( $organization, array $case ): void {
		if ( ! is_array( $organization ) ) { $this->invalid(); }
		$this->exact( $organization, array( 'agency_name', 'site_name', 'tenant_id' ) );
		if ( ! $this->text( $organization['agency_name'], 255 ) || ! $this->text( $organization['site_name'], 255 )
			|| $organization['tenant_id'] !== $case['tenant_id'] ) { $this->invalid(); }
	}

	/** @param mixed $subject @param array<string,mixed> $case */
	private function validate_subject( $subject, array $case ): void {
		if ( ! is_array( $subject ) ) { $this->invalid(); }
		$this->exact( $subject, array( 'display_name', 'email', 'employee_user_id', 'external_employee_key', 'group_ids', 'registered_at_gmt', 'role_keys' ) );
		if ( ! $this->text( $subject['display_name'], 255 ) || ! is_string( $subject['email'] )
			|| strlen( $subject['email'] ) > 254 || strtolower( $subject['email'] ) !== $subject['email']
			|| false === filter_var( $subject['email'], FILTER_VALIDATE_EMAIL )
			|| $subject['employee_user_id'] !== $case['employee_user_id']
			|| ( null !== $subject['external_employee_key'] && ! $this->text( $subject['external_employee_key'], 191 ) )
			|| ( null !== $subject['registered_at_gmt'] && ! $this->utc( $subject['registered_at_gmt'] ) )
			|| ! $this->decimal_list( $subject['group_ids'], true, true )
			|| ! $this->key_list( $subject['role_keys'], true ) ) { $this->invalid(); }
	}

	/** @param mixed $policy @param array<string,mixed> $identity */
	private function validate_policy( $policy, array $identity ): void {
		if ( ! is_array( $policy ) ) { $this->invalid(); }
		$this->exact( $policy, array( 'audit_mapping', 'completeness_policy', 'course_lifespan_rules', 'policy_digest', 'quiz_policy', 'relevant_settings', 'tracked_course_ids' ) );
		if ( 'snapshot_v1_complete' !== $policy['completeness_policy'] || $policy['policy_digest'] !== $identity['policy_digest']
			|| ! $this->decimal_list( $policy['tracked_course_ids'], false, true ) ) { $this->invalid(); }
		$tracked = $policy['tracked_course_ids'];
		$this->validate_course_keyed_object( $policy['audit_mapping'], $tracked, true );
		$this->validate_course_keyed_object( $policy['course_lifespan_rules'], $tracked, false );
		if ( ! is_array( $policy['quiz_policy'] ) ) { $this->invalid(); }
		$this->exact( $policy['quiz_policy'], array( 'attempt_selection', 'required', 'score_scale' ) );
		if ( 'latest_completed' !== $policy['quiz_policy']['attempt_selection'] || ! is_bool( $policy['quiz_policy']['required'] )
			|| 'basis_points' !== $policy['quiz_policy']['score_scale'] ) { $this->invalid(); }
		if ( ! is_array( $policy['relevant_settings'] ) ) { $this->invalid(); }
		$this->exact( $policy['relevant_settings'], array( 'annual_cycle', 'new_hire_deadline_days', 'warning_days' ) );
		if ( ! in_array( $policy['relevant_settings']['annual_cycle'], array( 'calendar_year', 'employee_start_date' ), true )
			|| ! $this->decimal( $policy['relevant_settings']['new_hire_deadline_days'] )
			|| ! $this->decimal( $policy['relevant_settings']['warning_days'] ) ) { $this->invalid(); }
	}

	/** @param mixed $object @param array<int,string> $tracked */
	private function validate_course_keyed_object( $object, array $tracked, bool $audit ): void {
		$members = $this->object_members( $object );
		$keys = array();
		foreach ( $members as $member ) {
			$keys[] = $member[0];
			$value = $member[1];
			if ( ! is_array( $value ) ) { $this->invalid(); }
			if ( $audit ) {
				$this->exact( $value, array( 'category_order', 'course_order', 'credit_minutes', 'is_orientation', 'odp_category_key', 'oltl_category_key' ) );
				if ( ! is_int( $value['category_order'] ) || $value['category_order'] < 0 || ! is_int( $value['course_order'] ) || $value['course_order'] < 0
					|| ! $this->decimal( $value['credit_minutes'] ) || ! is_bool( $value['is_orientation'] )
					|| ( null !== $value['odp_category_key'] && ! $this->machine_key( $value['odp_category_key'], 64 ) )
					|| ( null !== $value['oltl_category_key'] && ! $this->machine_key( $value['oltl_category_key'], 64 ) ) ) { $this->invalid(); }
			} else {
				$this->exact( $value, array( 'lifespan_days', 'warning_days' ) );
				if ( ! $this->decimal( $value['lifespan_days'] ) || ! $this->decimal( $value['warning_days'] ) ) { $this->invalid(); }
			}
		}
		if ( $keys !== $tracked ) { $this->invalid(); }
	}

	/** @param mixed $source @param array<string,mixed> $case */
	private function validate_source( $source, array $case ): void {
		if ( ! is_array( $source ) ) { $this->invalid(); }
		$this->exact( $source, array( 'learndash_version', 'plugin_version', 'source_adapter_key', 'source_adapter_version', 'source_record_ids', 'wordpress_version' ) );
		foreach ( array( 'learndash_version', 'plugin_version', 'wordpress_version' ) as $field ) {
			if ( ! $this->version( $source[ $field ] ) ) { $this->invalid(); }
		}
		if ( 'learndash-local' !== $source['source_adapter_key'] || '1.0.0' !== $source['source_adapter_version']
			|| ! is_array( $source['source_record_ids'] ) ) { $this->invalid(); }
		$ids = $source['source_record_ids'];
		$this->exact( $ids, array( 'course_activity_ids', 'course_post_ids', 'group_ids', 'quiz_activity_ids', 'user_id' ) );
		foreach ( array( 'course_activity_ids', 'course_post_ids', 'group_ids', 'quiz_activity_ids' ) as $field ) {
			if ( ! $this->decimal_list( $ids[ $field ], true, true ) ) { $this->invalid(); }
		}
		if ( $ids['user_id'] !== $case['employee_user_id'] ) { $this->invalid(); }
	}

	/** @param mixed $courses @param array<string,mixed> $policy @param array<string,mixed> $cycle */
	private function validate_courses( $courses, array $policy, array $cycle ): void {
		if ( ! is_array( $courses ) || ! $this->is_list( $courses ) || count( $courses ) > 10000 || count( $courses ) !== count( $policy['tracked_course_ids'] ) ) {
			$this->incomplete( 'normalize_limit' );
		}
		$ids = array();
		$previous = null;
		foreach ( $courses as $course ) {
			if ( ! is_array( $course ) ) { $this->invalid(); }
			$this->exact( $course, array(
				'category_order', 'certificate_reference', 'certificate_required', 'completed_at_gmt', 'completion_status',
				'course_id', 'course_order', 'course_stable_key', 'course_title', 'enrollment_status', 'pass_state',
				'quiz_attempts', 'quiz_score_basis_points', 'source_provenance', 'started_at_gmt', 'time_spent_seconds',
			) );
			if ( ! is_int( $course['category_order'] ) || $course['category_order'] < 0 || ! is_int( $course['course_order'] ) || $course['course_order'] < 0
				|| ! $this->positive_decimal( $course['course_id'] ) || ! $this->text( $course['course_title'], 255 )
				|| ( null !== $course['course_stable_key'] && ! $this->text( $course['course_stable_key'], 191 ) )
				|| ! in_array( $course['enrollment_status'], array( 'enrolled', 'not_enrolled' ), true )
				|| ! in_array( $course['completion_status'], array( 'not_started', 'in_progress', 'completed' ), true )
				|| ! in_array( $course['pass_state'], array( 'passed', 'failed', 'not_applicable', 'unknown' ), true )
				|| ! $this->decimal( $course['time_spent_seconds'] ) || ! is_bool( $course['certificate_required'] )
				|| ( null === $course['certificate_reference'] ) === $course['certificate_required']
				|| ( null !== $course['quiz_score_basis_points'] && ( ! is_int( $course['quiz_score_basis_points'] ) || $course['quiz_score_basis_points'] < 0 || $course['quiz_score_basis_points'] > 10000 ) )
				|| ( null !== $course['started_at_gmt'] && ! $this->within_cycle( $course['started_at_gmt'], $cycle ) )
				|| ( null !== $course['completed_at_gmt'] && ! $this->within_cycle( $course['completed_at_gmt'], $cycle ) )
				|| ( 'completed' === $course['completion_status'] ) !== ( null !== $course['completed_at_gmt'] )
				|| ( 'not_started' === $course['completion_status'] && ( null !== $course['started_at_gmt'] || '0' !== $course['time_spent_seconds'] ) )
				|| ( null !== $course['started_at_gmt'] && null !== $course['completed_at_gmt'] && strcmp( $course['started_at_gmt'], $course['completed_at_gmt'] ) > 0 ) ) {
				$this->invalid();
			}
			if ( null !== $course['certificate_reference'] ) {
				if ( ! is_array( $course['certificate_reference'] ) ) { $this->invalid(); }
				$this->exact( $course['certificate_reference'], array( 'certificate_post_id', 'source_record_version' ) );
				if ( ! $this->positive_decimal( $course['certificate_reference']['certificate_post_id'] ) || ! $this->version( $course['certificate_reference']['source_record_version'] ) ) { $this->invalid(); }
			}
			$this->validate_provenance( $course['source_provenance'] );
			$this->validate_attempts( $course['quiz_attempts'], $cycle );
			$order = array( $course['category_order'], $course['course_order'], $course['course_id'] );
			if ( null !== $previous && $this->compare_order( $previous, $order ) >= 0 ) { $this->invalid(); }
			$previous = $order;
			$ids[] = $course['course_id'];
		}
		if ( $ids !== $policy['tracked_course_ids'] ) { $this->invalid(); }
	}

	/** @param mixed $value */
	private function validate_provenance( $value ): void {
		if ( ! is_array( $value ) ) { $this->invalid(); }
		$this->exact( $value, array( 'adapter_key', 'record_id', 'record_version' ) );
		if ( 'learndash-local' !== $value['adapter_key'] || ! $this->text( $value['record_id'], 191 ) || ! $this->version( $value['record_version'] ) ) { $this->invalid(); }
	}

	/** @param mixed $attempts @param array<string,mixed> $cycle */
	private function validate_attempts( $attempts, array $cycle ): void {
		if ( ! is_array( $attempts ) || ! $this->is_list( $attempts ) ) { $this->invalid(); }
		foreach ( $attempts as $ordinal => $attempt ) {
			if ( ! is_array( $attempt ) ) { $this->invalid(); }
			$this->exact( $attempt, array( 'attempt_ordinal', 'attempted_at_gmt', 'passed', 'score_basis_points' ) );
			if ( $ordinal !== $attempt['attempt_ordinal'] || ! $this->within_cycle( $attempt['attempted_at_gmt'], $cycle )
				|| ! is_bool( $attempt['passed'] ) || ! is_int( $attempt['score_basis_points'] )
				|| $attempt['score_basis_points'] < 0 || $attempt['score_basis_points'] > 10000 ) { $this->invalid(); }
		}
	}

	/** @param mixed $completeness @param array<int,mixed> $courses @param array<string,mixed> $policy */
	private function validate_completeness( $completeness, array $courses, array $policy ): void {
		if ( ! is_array( $completeness ) ) { $this->invalid(); }
		$this->exact( $completeness, array( 'missing_fields', 'observed_count', 'policy_code', 'policy_version', 'required_count', 'result', 'warnings' ) );
		if ( array() !== $completeness['missing_fields'] || count( $courses ) !== $completeness['observed_count']
			|| 'snapshot_v1_complete' !== $completeness['policy_code'] || 1 !== $completeness['policy_version']
			|| count( $policy['tracked_course_ids'] ) !== $completeness['required_count'] || 'complete' !== $completeness['result']
			|| ! $this->key_list( $completeness['warnings'], true ) ) { $this->incomplete( 'source_validate' ); }
	}

	/** @param mixed $calculated @param array<int,mixed> $courses */
	private function validate_calculated( $calculated, array $courses ): void {
		if ( ! is_array( $calculated ) ) { $this->invalid(); }
		$this->exact( $calculated, array( 'calculation_version', 'categories', 'compliance_status', 'exceptions', 'matrix', 'total_course_count', 'total_training_seconds' ) );
		if ( 1 !== $calculated['calculation_version'] || ! in_array( $calculated['compliance_status'], array( 'compliant', 'non_compliant' ), true )
			|| ! $this->key_list( $calculated['exceptions'], true ) || count( $courses ) !== $calculated['total_course_count']
			|| ! $this->decimal( $calculated['total_training_seconds'] ) ) { $this->invalid(); }
		$this->validate_categories( $calculated['categories'] );
		$this->validate_matrix( $calculated['matrix'] );
		$total = '0';
		foreach ( $courses as $course ) { $total = $this->add_decimals( $total, $course['time_spent_seconds'] ); }
		if ( $total !== $calculated['total_training_seconds'] ) { $this->invalid(); }
	}

	/** @param mixed $categories */
	private function validate_categories( $categories ): void {
		foreach ( $this->object_members( $categories ) as $member ) {
			if ( ! $this->machine_key( $member[0], 64 ) || ! is_array( $member[1] ) ) { $this->invalid(); }
			$this->exact( $member[1], array( 'completed_course_ids', 'credit_minutes' ) );
			if ( ! $this->decimal_list( $member[1]['completed_course_ids'], true, true ) || ! $this->decimal( $member[1]['credit_minutes'] ) ) { $this->invalid(); }
		}
	}

	/** @param mixed $matrix */
	private function validate_matrix( $matrix ): void {
		if ( ! is_array( $matrix ) ) { $this->invalid(); }
		$this->exact( $matrix, array( 'odp', 'oltl' ) );
		foreach ( array( 'odp', 'oltl' ) as $kind ) {
			foreach ( $this->object_members( $matrix[ $kind ] ) as $member ) {
				if ( ! $this->machine_key( $member[0], 64 ) || ! is_bool( $member[1] ) ) { $this->invalid(); }
			}
		}
	}

	/** @param array<string,mixed> $actual @param array<int,string> $expected */
	private function exact( array $actual, array $expected ): void {
		$keys = array_keys( $actual );
		$extra = array_diff( $keys, $expected );
		if ( array() !== $extra ) { $this->prohibited(); }
		$sorted = $keys;
		sort( $sorted, SORT_STRING );
		$wanted = $expected;
		sort( $wanted, SORT_STRING );
		if ( $sorted !== $wanted ) { $this->incomplete( 'source_validate' ); }
	}

	/** @param mixed $value */
	private function assert_no_prohibited( $value ): void {
		if ( is_resource( $value ) || ( is_object( $value )
			&& ! $value instanceof GHCA_ACD_Archive_Empty_Object
			&& ! $value instanceof GHCA_ACD_Archive_Canonical_Object ) ) {
			$this->prohibited();
		}
		if ( $value instanceof GHCA_ACD_Archive_Canonical_Object ) {
			foreach ( $value->members() as $member ) {
				$this->assert_no_prohibited( $member[1] );
			}
			return;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) { $this->assert_no_prohibited( $item ); }
			return;
		}
		if ( ! is_string( $value ) ) { return; }
		if ( false !== strpos( $value, "\r" )
			|| 1 === preg_match( '/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/', $value )
			|| preg_match( '/<[^>]*>/', $value )
			|| preg_match( '/[A-Za-z][A-Za-z0-9+.-]*:\\/\\//', $value )
			|| preg_match( '/(?:^[A-Za-z]:[\\\\\\/]|^[\\\\\\/]|(?:^|[\\\\\\/])\\.\\.?[\\\\\\/])/', $value )
			|| html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) !== $value ) {
			$this->prohibited();
		}
	}

	/** @param mixed $value @return array<int,array{0:string,1:mixed}> */
	private function object_members( $value ): array {
		if ( $value instanceof GHCA_ACD_Archive_Empty_Object ) { return array(); }
		if ( $value instanceof GHCA_ACD_Archive_Canonical_Object ) { return $value->members(); }
		if ( ! is_array( $value ) || $this->is_list( $value ) ) { $this->invalid(); }
		$members = array();
		foreach ( $value as $key => $item ) {
			if ( ! is_string( $key ) ) { $this->invalid(); }
			$members[] = array( $key, $item );
		}
		usort( $members, static function ( array $left, array $right ): int { return strcmp( $left[0], $right[0] ); } );
		return $members;
	}

	/** @param mixed $value */
	private function text( $value, int $maximum ): bool {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > $maximum || 1 !== preg_match( '//u', $value )
			|| trim( $value ) !== $value || false !== strpos( $value, "\r" )
			|| 1 === preg_match( '/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/', $value )
			|| preg_match( '/<[^>]*>/', $value ) || preg_match( '/[A-Za-z][A-Za-z0-9+.-]*:\\/\\//', $value )
			|| preg_match( '/(?:^[A-Za-z]:[\\\\\\/]|^[\\\\\\/]|(?:^|[\\\\\\/])\\.\\.?[\\\\\\/])/', $value )
			|| html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) !== $value ) { return false; }
		if ( ! class_exists( 'Normalizer' ) ) {
			return 1 !== preg_match( '/[^\x00-\x7F]/', $value );
		}
		return Normalizer::isNormalized( $value, Normalizer::FORM_C );
	}

	/** @param mixed $value */
	private function machine_key( $value, int $maximum ): bool {
		return is_string( $value ) && strlen( $value ) <= $maximum && 1 === preg_match( '/^[a-z][a-z0-9_-]*$/', $value );
	}
	/** @param mixed $value */
	private function version( $value ): bool { return is_string( $value ) && strlen( $value ) <= 64 && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._+-]*$/', $value ); }
	/** @param mixed $value */
	private function id( $value ): bool { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $value ); }
	/** @param mixed $value */
	private function digest( $value ): bool { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $value ); }
	/** @param mixed $value */
	private function decimal( $value ): bool {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(?:0|[1-9][0-9]*)$/', $value ) ) { return false; }
		$max = '18446744073709551615';
		return strlen( $value ) < strlen( $max ) || ( strlen( $value ) === strlen( $max ) && strcmp( $value, $max ) <= 0 );
	}
	/** @param mixed $value */
	private function positive_decimal( $value ): bool { return $this->decimal( $value ) && '0' !== $value; }
	/** @param mixed $value */
	private function decimal_list( $value, bool $allow_empty, bool $sorted ): bool {
		if ( ! is_array( $value ) || ! $this->is_list( $value ) || ( ! $allow_empty && array() === $value ) ) { return false; }
		$seen = array();
		$previous = null;
		foreach ( $value as $item ) {
			if ( ! $this->positive_decimal( $item ) || isset( $seen[ $item ] ) || ( $sorted && null !== $previous && $this->compare_decimal( $previous, $item ) >= 0 ) ) { return false; }
			$seen[ $item ] = true;
			$previous = $item;
		}
		return true;
	}
	/** @param mixed $value */
	private function key_list( $value, bool $sorted ): bool {
		if ( ! is_array( $value ) || ! $this->is_list( $value ) ) { return false; }
		$copy = $value;
		if ( $sorted ) { sort( $copy, SORT_STRING ); }
		foreach ( $value as $item ) { if ( ! $this->machine_key( $item, 191 ) ) { return false; } }
		return count( array_unique( $value, SORT_STRING ) ) === count( $value ) && ( ! $sorted || $copy === $value );
	}
	/** @param mixed $value */
	private function utc( $value ): bool {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/', $value ) ) { return false; }
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d\\TH:i:s\\Z', $value, new DateTimeZone( 'UTC' ) );
		return false !== $date && $date->format( 'Y-m-d\\TH:i:s\\Z' ) === $value;
	}
	private function timezone( string $value ): bool {
		try { return ( new DateTimeZone( $value ) )->getName() === $value; } catch ( Throwable $error ) { return false; }
	}
	/** @param array<string,mixed> $cycle */
	private function within_cycle( string $value, array $cycle ): bool { return $this->utc( $value ) && strcmp( $value, $cycle['start_gmt'] ) >= 0 && strcmp( $value, $cycle['end_gmt'] ) < 0; }
	/** @param array<int,mixed> $left @param array<int,mixed> $right */
	private function compare_order( array $left, array $right ): int {
		if ( $left[0] !== $right[0] ) { return $left[0] <=> $right[0]; }
		if ( $left[1] !== $right[1] ) { return $left[1] <=> $right[1]; }
		return $this->compare_decimal( $left[2], $right[2] );
	}
	private function compare_decimal( string $left, string $right ): int { return strlen( $left ) === strlen( $right ) ? strcmp( $left, $right ) : strlen( $left ) <=> strlen( $right ); }
	private function add_decimals( string $left, string $right ): string {
		$i = strlen( $left ) - 1; $j = strlen( $right ) - 1; $carry = 0; $out = '';
		while ( $i >= 0 || $j >= 0 || $carry ) {
			$sum = $carry + ( $i >= 0 ? ord( $left[ $i-- ] ) - 48 : 0 ) + ( $j >= 0 ? ord( $right[ $j-- ] ) - 48 : 0 );
			$out = (string) ( $sum % 10 ) . $out; $carry = intdiv( $sum, 10 );
		}
		return ltrim( $out, '0' ) ?: '0';
	}
	/** @param array<mixed,mixed> $value */
	private function is_list( array $value ): bool {
		$index = 0;
		foreach ( $value as $key => $_item ) { if ( $key !== $index++ ) { return false; } }
		return true;
	}
	private function invalid(): void { throw new GHCA_ACD_Archive_Evidence_Source_Exception( GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_INVALID, 'archive_snapshot_invalid', 'source_validate' ); }
	private function prohibited(): void { throw new GHCA_ACD_Archive_Evidence_Source_Exception( GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_INVALID, 'archive_evidence_prohibited', 'source_validate' ); }
	private function incomplete( string $context ): void { throw new GHCA_ACD_Archive_Evidence_Source_Exception( GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_INVALID, 'archive_evidence_incomplete', $context ); }
}
