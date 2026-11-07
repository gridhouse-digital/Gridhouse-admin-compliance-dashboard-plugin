<?php
/**
 * The legacy Overdue, Courses and Certificates output in a fixed set of states, keyed by name.
 * Shared by the golden capture and tests/test-redesign-lists.php so both render exactly the same.
 *
 * @return array<string,string>
 */
function ghca_legacy_lists_states(): array {
	$base  = array( 'logged_in' => true, 'can_view' => true, 'manage_users' => true, 'redesign' => false, 'now' => strtotime( '2026-11-01 12:00:00 UTC' ) );
	$runs  = array(
		'priority_default'   => array( array(), array(), 'priority' ),
		'priority_overdue'   => array( array(), array( 'ghca_pri_type' => 'overdue' ), 'priority' ),
		'priority_group'     => array( array(), array( 'ghca_pri_group' => '12' ), 'priority' ),
		'priority_search'    => array( array(), array( 'ghca_pri_search' => 'nobody' ), 'priority' ),
		'priority_sorted'    => array( array( 'can_admin' => true ), array( 'ghca_orderby' => 'name', 'ghca_order' => 'desc' ), 'priority' ),
		'priority_paged'     => array( array(), array( 'ghca_pri_per' => '10', 'ghca_pri_page' => '1' ), 'priority' ),
		'courses_default'    => array( array(), array(), 'courses' ),
		'courses_cert_yes'   => array( array(), array( 'ghca_crs_cert' => 'yes' ), 'courses' ),
		'courses_cert_no'    => array( array(), array( 'ghca_crs_cert' => 'no' ), 'courses' ),
		'courses_search'     => array( array(), array( 'ghca_crs_search' => 'fatal' ), 'courses' ),
		'courses_empty'      => array( array(), array( 'ghca_crs_search' => 'zzz' ), 'courses' ),
		'certificates_full'  => array( array(), array(), 'certificates' ),
		'certificates_compact' => array( array(), array(), 'certificates_compact' ),
		'overdue_panel'      => array( array(), array(), 'overdue_panel' ),
		'courses_panel'      => array( array(), array(), 'courses_panel' ),
	);
	$out = array();
	foreach ( $runs as $name => $run ) {
		$GLOBALS['ghca_env'] = array_merge( $base, $run[0] );
		$_GET  = $run[1];
		$_POST = array();
		ghca_lists_seed();
		switch ( $run[2] ) {
			case 'priority':
				$out[ $name ] = GHCA_ACD_Shortcodes::get_priority_table_html( GHCA_ACD_Data_Provider::get_priority_filters() );
				break;
			case 'courses':
				$out[ $name ] = GHCA_ACD_Shortcodes::get_course_table_html( GHCA_ACD_Data_Provider::get_course_filters() );
				break;
			case 'certificates':
				$out[ $name ] = GHCA_ACD_Shortcodes::render_certificate_tracking();
				break;
			case 'certificates_compact':
				$out[ $name ] = GHCA_ACD_Shortcodes::render_certificate_tracking( array( 'variant' => 'compact' ) );
				break;
			case 'overdue_panel':
				$out[ $name ] = GHCA_ACD_Shortcodes::render_overdue_employees();
				break;
			case 'courses_panel':
				$out[ $name ] = GHCA_ACD_Shortcodes::render_course_overview();
				break;
		}
	}
	$_GET = array();
	return $out;
}
