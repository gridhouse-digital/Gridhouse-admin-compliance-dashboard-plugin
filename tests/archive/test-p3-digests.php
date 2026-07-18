<?php
require __DIR__ . '/bootstrap.php';

$literal = "ghca-task-outcome-v1\n{\"logical_outcome\":\"completed\",\"task_id\":\"0123456789abcdef0123456789abcdef\",\"task_schema_version\":1}";
$expected = '8b46a186347750fff01afe7555072501c075e86a87bcf960a5bd7cf64fc75e0c';

archive_check( 121 === strlen( $literal ), 'TASK-OUTCOME-GOLDEN-LITERAL-BYTES exact independent vector is 121 bytes' );
archive_check( $expected === hash( 'sha256', $literal ), 'TASK-OUTCOME-GOLDEN-FIXED-SHA256 independent literal hashes to the frozen constant' );

$document = array(
	'logical_outcome'    => 'completed',
	'task_id'            => '0123456789abcdef0123456789abcdef',
	'task_schema_version' => 1,
);
archive_check( $expected === GHCA_ACD_Archive_Digester::task_outcome( $document ), 'TASK-OUTCOME-PRODUCTION-HELPER matches the independent frozen constant' );
archive_check( in_array( PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, array( '8.3', '8.5' ), true ), 'TASK-OUTCOME-CROSS-RUNTIME executes on one approved PHP runtime' );

function p3_expect_outcome_failure( array $document, string $name ): void {
	$caught = null;
	try {
		GHCA_ACD_Archive_Digester::task_outcome( $document );
	} catch ( Throwable $error ) {
		$caught = $error;
	}
	archive_check(
		$caught instanceof GHCA_ACD_Archive_Persistence_Exception
			&& 'task_outcome_idempotency_invalid' === $caught->reason_code(),
		$name . ' uses the exact persistence exception and stable reason'
	);
}

$extra = $document;
$extra['extra'] = 1;
p3_expect_outcome_failure( $extra, 'TASK-OUTCOME-EXTRA-CONSTITUENT' );

$missing = $document;
unset( $missing['task_id'] );
p3_expect_outcome_failure( $missing, 'TASK-OUTCOME-MISSING-CONSTITUENT' );

$reordered = array(
	'task_id'            => $document['task_id'],
	'logical_outcome'    => 'completed',
	'task_schema_version' => 1,
);
p3_expect_outcome_failure( $reordered, 'TASK-OUTCOME-REORDERED-CONSTITUENTS' );

$malformed = $document;
$malformed['task_id'] = 'ABC';
p3_expect_outcome_failure( $malformed, 'TASK-OUTCOME-MALFORMED-CONSTITUENT' );

$unsupported = $document;
$unsupported['task_schema_version'] = 2;
p3_expect_outcome_failure( $unsupported, 'TASK-OUTCOME-UNSUPPORTED-CONSTITUENT' );

archive_finish();
