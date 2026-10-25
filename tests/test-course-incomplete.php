<?php
// Synthetic, in-memory handler/storage checks. No site records, network or notifications.
require __DIR__ . '/test-employment-type.php';
function get_the_title( $id ) { return 'Synthetic course'; }
function learndash_course_completed( $u, $c ) { return ! empty( $GLOBALS['meta'][$u]['course_completed_' . $c] ); }
function add_user_meta( $u, $key, $value ) {
    if ( ! empty( $GLOBALS['fail_history'] ) ) { return false; }
    $GLOBALS['meta'][$u][$key][] = $value; return true;
}
function delete_user_meta( $u, $key ) { unset( $GLOBALS['meta'][$u][$key] ); return true; }
function learndash_course_get_steps_by_type( $c, $type ) { return 'sfwd-lessons' === $type ? ( $GLOBALS['lessons_fixture'] ?? array( 101, 102 ) ) : array(); }
function learndash_has_global_quizzes( $c ) { return ! empty( $GLOBALS['quiz_only'] ); }
function learndash_is_all_global_quizzes_complete( $u, $c ) { return ! empty( $GLOBALS['quiz_only'] ); }
function learndash_get_course_steps_count( $c ) { return 2; }
function learndash_user_get_course_progress( $u, $c, $type = '' ) { return $GLOBALS['meta'][$u]['_sfwd-course_progress'][$c] ?? array(); }
function learndash_user_set_course_progress( $u, $c, $p ) { $GLOBALS['meta'][$u]['_sfwd-course_progress'][$c] = $p; return true; }
function learndash_get_user_activity( $args ) { return isset( $GLOBALS['activity_fixture'][$args['post_id']] ) ? (object) $GLOBALS['activity_fixture'][$args['post_id']] : false; }
function learndash_update_user_activity( $args ) {
    if ( ! empty( $GLOBALS['fail_activity'] ) ) { return false; }
    $GLOBALS['activity_fixture'][$args['post_id']] = array_merge( $GLOBALS['activity_fixture'][$args['post_id']] ?? array(), $args ); return true;
}
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function incomplete_fixture() {
    $GLOBALS['enrolled_fixture'] = array( 99 );
    $GLOBALS['lessons_fixture'] = array( 101, 102 );
    $GLOBALS['quiz_only'] = $GLOBALS['fail_history'] = $GLOBALS['fail_activity'] = false;
    $GLOBALS['meta'][11]['course_completed_99'] = 123;
    $GLOBALS['meta'][11]['_sfwd-course_progress'] = array( 99 => array( 'status' => 'completed', 'completed' => 2, 'total' => 2, 'lessons' => array( 101 => 1, 102 => 1 ) ), 100 => array( 'status' => 'completed' ) );
    $GLOBALS['meta'][11]['_sfwd-quizzes'] = array( array( 'course' => 99, 'pass' => 1 ) );
    $GLOBALS['activity_fixture'] = array( 99 => array( 'activity_status' => 1, 'activity_completed' => 123 ), 102 => array( 'activity_status' => 1, 'activity_completed' => 122 ) );
}
incomplete_fixture();
$row = array( 'course' => array( 99 => array( 'mark_complete' => '0', 'completed' => '2020-01-01T12:00' ) ) );
verify_type( submit_type( 'ajax_save_employee_records', $row ), 'Unchecked row saves despite prefilled completion date' );
verify_type( ! get_user_meta( 11, 'course_completed_99', true ) && 0 === $activity_fixture[99]['activity_completed'] && false === $activity_fixture[99]['activity_status'], 'Completion meta and course activity cleared' );
verify_type( 0 === $meta[11]['_sfwd-course_progress'][99]['lessons'][102] && 1 === $meta[11]['_sfwd-course_progress'][99]['lessons'][101], 'Only last completed lesson reopened to prevent recompletion' );
verify_type( 1 === $meta[11]['_sfwd-quizzes'][0]['pass'] && 'completed' === $meta[11]['_sfwd-course_progress'][100]['status'], 'Quiz attempts and other courses preserved' );
$h = end( $meta[11]['ghca_acd_course_correction_history'] );
verify_type( 90 === $h['actor_id'] && 123 === $h['completion_date'] && 1 === $h['progress']['lessons'][102], 'Previous completion and actor retained before correction' );
$count = count( $meta[11]['ghca_acd_course_correction_history'] );
verify_type( submit_type( 'ajax_save_employee_records', $row ) && $count === count( $meta[11]['ghca_acd_course_correction_history'] ), 'Repeated incomplete save is no-op' );
verify_type( submit_type( 'ajax_save_employee_records', array( 'course' => array( 99 => array( 'mark_complete' => '1', 'completed' => '2020-01-01T12:00' ) ) ) ) && learndash_course_completed( 11, 99 ), 'Rechecking still supports intentional completion' );
foreach ( array( 'nonce_ok', 'permission_ok', 'scope_ok' ) as $gate ) {
    incomplete_fixture(); $GLOBALS[$gate] = false; $before = $meta;
    verify_type( ! submit_type( 'ajax_save_employee_records', $row ) && $before === $meta, 'Authorization prevents correction: ' . $gate ); $GLOBALS[$gate] = true;
}
incomplete_fixture(); $before = $meta;
verify_type( submit_type( 'ajax_save_employee_records', array( 'course' => array( 98 => array( 'mark_complete' => '0' ) ) ) ) && $before === $meta, 'Unenrolled course not editable' );
verify_type( submit_type( 'ajax_save_employee_records', array( 'course' => array( 99 => array() ) ) ) && $before === $meta, 'Missing checkbox does not clear completion' );
$fail_history = true;
foreach ( array( 'bad', array( '0' ), null ) as $invalid ) {
    verify_type( ! submit_type( 'ajax_save_employee_records', array( 'course' => array( 99 => array( 'mark_complete' => $invalid ) ) ) ) && $before === $meta, 'Malformed checkbox is rejected without changes' );
}
verify_type( ! submit_type( 'ajax_save_employee_records', $row ) && $before === $meta, 'History write failure prevents correction' );
incomplete_fixture(); $fail_activity = true;
verify_type( ! submit_type( 'ajax_save_employee_records', $row ), 'Partial storage failure is reported, not success' );
incomplete_fixture(); unset( $meta[11]['_sfwd-course_progress'][99] );
verify_type( submit_type( 'ajax_save_employee_records', $row ) && ! get_user_meta( 11, 'course_completed_99', true ), 'Orphan completion meta/activity is corrected' );
incomplete_fixture(); $lessons_fixture = array(); $quiz_only = true; $before = $meta;
verify_type( ! submit_type( 'ajax_save_employee_records', $row ) && $before === $meta, 'Quiz-only auto-completion fails safely without deleting attempts' );
echo "PASS: $checks cumulative incomplete-course handler checks\n";
