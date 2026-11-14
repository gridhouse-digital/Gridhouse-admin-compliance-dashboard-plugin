<?php
/**
 * GHCA_ACD_Course_Title: readable Title Case for stored course titles (presentation only). Synthetic titles.
 */
define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/class-course-title.php';
$failed = 0; $checks = 0;
function check( bool $ok, string $m ): void { global $failed, $checks; ++$checks; echo ( $ok ? 'ok - ' : 'FAIL - ' ) . $m . "\n"; if ( ! $ok ) { ++$failed; } }
$cases = array(
	'FATAL FIVE'                                                          => 'Fatal Five',
	'CPR/FIRST AID'                                                        => 'CPR/First Aid',
	'CPR / FIRST AID CERTIFICATION'                                        => 'CPR / First Aid Certification',
	'PREVENTION, DETECTION & REPORTING OF ABUSE (OAPSA, CPS LAW, APS ACT)' => 'Prevention, Detection & Reporting of Abuse (OAPSA, CPS Law, APS Act)',
	'PERSON-CENTERED PRACTICES & COMMUNITY INTEGRATION'                    => 'Person-Centered Practices & Community Integration',
	'HIPAA AND THE ODP: WHAT TO KNOW'                                      => 'HIPAA and the ODP: What to Know',
	'ODP101 ORIENTATION MODULE 3'                                          => 'ODP101 Orientation Module 3',
	'MEDICATION ADMINISTRATION (PA DHS) PART II'                           => 'Medication Administration (PA DHS) Part II',
	"EMPLOYEE'S RIGHTS"                                                    => "Employee's Rights",
	'A NEW HIRE ORIENTATION'                                               => 'A New Hire Orientation',
	'THE ISP PROCESS'                                                      => 'The ISP Process',
	'WORKING WITH ISPS'                                                    => 'Working with ISPs',
	'IT IS ABOUT US'                                                       => 'It Is About Us',
	'Documentation Essentials for Healthcare and IDD Services'             => 'Documentation Essentials for Healthcare and IDD Services',
	'iPad basics for DSPs'                                                 => 'iPad Basics for DSPs',
	'fatal five'                                                           => 'Fatal Five',
	'CMS-485 PLAN OF CARE'                                                 => 'CMS-485 Plan of Care',
	'  TRAUMA   INFORMED  CARE '                                           => 'Trauma Informed Care',
	'SAFE AND APPROPRIATE USE OF BEHAVIOR SUPPORTS'                        => 'Safe and Appropriate Use of Behavior Supports',
	'COURSE: AN INTRODUCTION'                                              => 'Course: An Introduction',
	'<b>Lift & transfer</b>'                                               => '<b>Lift & Transfer</b>',
	'FIRST AID / CPR – PART I'                                             => 'First Aid / CPR – Part I',
	''                                                                     => '',
);
foreach ( $cases as $in => $want ) {
	$got = GHCA_ACD_Course_Title::format( $in );
	check( $want === $got, var_export( $in, true ) . ' => ' . var_export( $got, true ) . ( $want === $got ? '' : ' (wanted ' . var_export( $want, true ) . ')' ) );
}
echo $failed ? "FAILED: $failed of $checks course title checks\n" : "PASS: $checks course title checks\n";
exit( $failed ? 1 : 0 );
