<?php
/**
 * Plugin Name: GHCA disposable-site LearnDash stand-ins (tests only)
 * Description: Synthetic LearnDash groups and functions so the dashboard renders on a disposable WordPress site
 * without LearnDash. Loads only when wp-config.php defines GHCA_DISPOSABLE_TEST_SITE. Never install on staging or live.
 */
if ( ! defined( 'GHCA_DISPOSABLE_TEST_SITE' ) ) {
	return;
}
add_action( 'init', static function () {
	if ( ! post_type_exists( 'groups' ) ) {
		register_post_type( 'groups', array( 'public' => false, 'label' => 'Groups' ) );
	}
}, 1 );
add_filter( 'pre_wp_mail', '__return_false' ); // nothing leaves this site

function ghca_rs_groups( int $uid ): array { return array_map( 'intval', (array) get_user_meta( $uid, 'ghca_rs_groups', true ) ); }
if ( ! function_exists( 'learndash_get_users_group_ids' ) ) {
	function learndash_get_users_group_ids( $user_id = 0, $bypass = false ) { return ghca_rs_groups( (int) $user_id ); }
	function learndash_set_users_group_ids( $user_id = 0, $ids = array() ) { update_user_meta( (int) $user_id, 'ghca_rs_groups', array_values( array_map( 'intval', (array) $ids ) ) ); }
	function learndash_get_groups_user_ids( $group_id = 0, $bypass = false ) {
		$ids = array();
		foreach ( get_users( array( 'fields' => 'ID', 'meta_key' => 'ghca_rs_groups' ) ) as $uid ) {
			if ( in_array( (int) $group_id, ghca_rs_groups( (int) $uid ), true ) ) { $ids[] = (int) $uid; }
		}
		return $ids;
	}
	function learndash_is_user_in_group( $user_id, $group_id ) { return in_array( (int) $group_id, ghca_rs_groups( (int) $user_id ), true ); }
	function learndash_groups() { return get_posts( array( 'post_type' => 'groups', 'numberposts' => -1, 'post_status' => 'publish' ) ); }
	function learndash_group_enrolled_courses( $group_id = 0, $bypass = false ) { return array(); }
	function learndash_get_groups_courses_ids( $user_id = 0, $group_ids = array() ) { return array(); }
	// Groups a synthetic group leader leads (slice 8 scope tests).
	function learndash_get_administrators_group_ids( $user_id = 0, $bypass = false ) { return array_map( 'intval', (array) get_user_meta( (int) $user_id, 'ghca_rs_leads', true ) ); }
	function learndash_is_group_leader_user( $user = 0 ) { return false; }
	function learndash_is_admin_user( $user = 0 ) { return user_can( $user ?: get_current_user_id(), 'manage_options' ); }
	function learndash_user_get_enrolled_courses( $user_id = 0, $args = array(), $bypass = false ) { return array(); }
	function learndash_course_completed( $user_id, $course_id ) { return false; }
	function learndash_course_progress( $args = array() ) { return array(); }
	function learndash_course_status( $course_id, $user_id = null ) { return ''; }
	function learndash_get_course_certificate_link( $course_id, $user_id = null ) { return ''; }
	function learndash_user_get_course_progress( $user_id = 0, $course_id = 0, $type = 'summary' ) { return array(); }
	function learndash_get_user_activity( $args = array() ) { return null; }
	function learndash_get_setting( $post = null, $key = null ) { return ''; }
	function learndash_get_post_type_slug( $type = '' ) { return 'groups' === $type || 'group' === $type ? 'groups' : 'sfwd-' . $type; }
}
