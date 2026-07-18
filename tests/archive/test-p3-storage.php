<?php
require __DIR__ . '/persistence-bootstrap.php';

$p3_roots = array();

const GHCA_P3_CURSOR_HMAC_TEST_KEY = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
const GHCA_P3_CURSOR_HMAC_WRONG_KEY = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';

function p3_storage_id( string $seed ): string {
	return substr( hash( 'sha256', 'p3-storage|' . $seed ), 0, 32 );
}

/** @return array<string,string> */
function p3_identity( string $seed ): array {
	return array(
		'tenant_id'   => p3_storage_id( $seed . '|tenant' ),
		'stream_id'   => p3_storage_id( $seed . '|stream' ),
		'archive_id'  => p3_storage_id( $seed . '|archive' ),
		'artifact_id' => p3_storage_id( $seed . '|artifact' ),
	);
}

function p3_new_root(): string {
	global $p3_roots;
	$parent = realpath( sys_get_temp_dir() );
	if ( false === $parent ) {
		throw new RuntimeException( 'system temp directory is unavailable' );
	}
	$root = $parent . DIRECTORY_SEPARATOR . 'ghca_acd_archive_test_' . bin2hex( random_bytes( 16 ) );
	if ( ! mkdir( $root, 0700 ) || ! chmod( $root, 0700 ) ) {
		throw new RuntimeException( 'test storage root could not be created' );
	}
	$root = realpath( $root );
	$p3_roots[] = $root;
	return $root;
}

function p3_store( string $root, string $cursor_hmac_key = GHCA_P3_CURSOR_HMAC_TEST_KEY ): GHCA_ACD_Private_Archive_Artifact_Store {
	return new GHCA_ACD_Private_Archive_Artifact_Store( $root, array( ABSPATH ), $cursor_hmac_key );
}

function p3_path( string $root, string $key ): string {
	return $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $key );
}

function p3_write_source( string $root, string $name, string $bytes ) {
	$path = $root . DIRECTORY_SEPARATOR . $name;
	$handle = fopen( $path, 'w+b' );
	fwrite( $handle, $bytes );
	fflush( $handle );
	rewind( $handle );
	return $handle;
}

function p3_pdf( string $filler = '' ): string {
	$header = "%PDF-1.4\n";
	$offset = strlen( $header ) + strlen( $filler );
	return $header . $filler . "xref\n0 1\n0000000000 65535 f \nstartxref\n{$offset}\n%%EOF\n";
}

function p3_xref_stream_pdf(): string {
	$header = "%PDF-1.7\n";
	$offset = strlen( $header );
	$object = "1 0 obj\n<< /Type /XRef /Length 0 >>\nstream\n\nendstream\nendobj\n";
	return $header . $object . "startxref\n{$offset}\n%%EOF\n";
}

function p3_make_pdf_file( string $path, int $target_size ): void {
	$header = "%PDF-1.7\n";
	$offset = $target_size - 64;
	do {
		$trailer = "xref\n0 1\n0000000000 65535 f \nstartxref\n{$offset}\n%%EOF\n";
		$new_offset = $target_size - strlen( $trailer );
		$changed = $new_offset !== $offset;
		$offset = $new_offset;
	} while ( $changed );
	$filler = $offset - strlen( $header );
	$handle = fopen( $path, 'w+b' );
	fwrite( $handle, $header );
	$chunk = str_repeat( 'A', GHCA_ACD_Private_Archive_Artifact_Store::CHUNK_BYTES );
	while ( $filler > 0 ) {
		$length = min( strlen( $chunk ), $filler );
		fwrite( $handle, 0 === $length - strlen( $chunk ) ? $chunk : substr( $chunk, 0, $length ) );
		$filler -= $length;
	}
	fwrite( $handle, $trailer );
	fflush( $handle );
	if ( ftell( $handle ) !== $target_size ) {
		throw new RuntimeException( 'PDF fixture size mismatch' );
	}
	fclose( $handle );
}

/** @return array<string,mixed> */
function p3_stage_file( GHCA_ACD_Private_Archive_Artifact_Store $store, array $identity, string $kind, string $source_path ): array {
	$key = $store->create_staging( $identity );
	$source = fopen( $source_path, 'rb' );
	try {
		$meta = $store->write_staging( $key, $source, $kind );
	} finally {
		fclose( $source );
	}
	$meta['staging_key'] = $key;
	return $meta;
}

function p3_expect_store_failure( callable $callback, string $reason, string $name ): void {
	$caught = null;
	try {
		$callback();
	} catch ( Throwable $error ) {
		$caught = $error;
	}
	archive_check( $caught instanceof GHCA_ACD_Archive_Artifact_Store_Exception && $reason === $caught->reason_code(), $name . ' uses the exact artifact-store exception and reason' );
}

/** @param array<string,mixed> $cursor */
function p3_expect_cursor_auth_failure( GHCA_ACD_Archive_Orphan_Reconciler $reconciler, array $cursor, $db, string $root, int &$descriptor_queries, string $name ): void {
	$db_before         = ghca_persist_db_fingerprint( $db );
	$filesystem_before = p3_tree_fingerprint( $root );
	$queries_before    = $descriptor_queries;
	$caught            = null;
	$result            = null;
	try {
		$result = $reconciler->reconcile( $cursor );
	} catch ( Throwable $error ) {
		$caught = $error;
	}
	archive_check(
		null === $result
		&& null !== $caught
		&& GHCA_ACD_Archive_Artifact_Store_Exception::class === get_class( $caught )
		&& 'orphan_cursor_invalid' === $caught->reason_code(),
		$name . ' rejects before candidate emission with the exact store exception and reason'
	);
	archive_check(
		$db_before === ghca_persist_db_fingerprint( $db )
		&& $filesystem_before === p3_tree_fingerprint( $root )
		&& $queries_before === $descriptor_queries,
		$name . '-NO-RESIDUE performs zero descriptor queries and changes neither database nor filesystem state'
	);
}

function p3_tree_fingerprint( string $root ): string {
	$rows = array();
	$walk = function ( string $directory, string $relative ) use ( &$walk, &$rows ): void {
		$entries = scandir( $directory, SCANDIR_SORT_ASCENDING );
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $directory . DIRECTORY_SEPARATOR . $entry;
			$key = '' === $relative ? $entry : $relative . '/' . $entry;
			if ( is_link( $path ) ) {
				$rows[] = array( $key, 'link', readlink( $path ) );
			} elseif ( is_dir( $path ) ) {
				$rows[] = array( $key, 'dir' );
				$walk( $path, $key );
			} else {
				$rows[] = array( $key, 'file', filesize( $path ), hash_file( 'sha256', $path ) );
			}
		}
	};
	$walk( $root, '' );
	return hash( 'sha256', json_encode( $rows ) );
}

function p3_cleanup_root( string $root ): void {
	$parent = realpath( sys_get_temp_dir() );
	$real   = is_link( $root ) ? false : realpath( $root );
	if ( false === $parent || false === $real || realpath( dirname( $real ) ) !== $parent
		|| 1 !== preg_match( '/^ghca_acd_archive_test_[a-f0-9]{32}$/', basename( $real ) ) ) {
		throw new GHCA_ACD_Archive_Artifact_Store_Exception( 'test_cleanup_boundary_violation', 'Test cleanup target is outside the isolated storage boundary.' );
	}
	$remove = function ( string $directory ) use ( &$remove ): void {
		foreach ( scandir( $directory, SCANDIR_SORT_DESCENDING ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $directory . DIRECTORY_SEPARATOR . $entry;
			if ( is_link( $path ) || is_file( $path ) ) {
				unlink( $path );
			} elseif ( is_dir( $path ) ) {
				$remove( $path );
				rmdir( $path );
			} else {
				throw new GHCA_ACD_Archive_Artifact_Store_Exception( 'test_storage_root_invalid', 'Unsupported object inside test storage root.' );
			}
		}
	};
	$remove( $real );
	rmdir( $real );
}

/** @return array<string,mixed> */
function p3_descriptor( array $identity, string $key, string $kind, int $count, string $digest, string $seed ): array {
	return array(
		'descriptor' => array(
			'artifact_id' => $identity['artifact_id'], 'artifact_kind' => $kind, 'artifact_schema_version' => 1,
			'byte_count' => $count, 'content_digest' => $digest, 'content_digest_algorithm' => 'sha256',
			'filename' => $kind . '.pdf', 'media_type' => 'application/pdf', 'producer_key' => 'ghca.p3-test',
			'producer_version' => '1.0.0', 'role_key' => $kind, 'storage_adapter' => 'private_local', 'storage_key' => $key,
		),
		'binding' => array(
			'archive_id' => $identity['archive_id'], 'build_attempt_id' => p3_storage_id( $seed . '|attempt' ),
			'created_at_gmt' => '2026-07-16T12:00:00Z', 'snapshot_digest' => hash( 'sha256', $seed . '|snapshot' ),
			'snapshot_id' => p3_storage_id( $seed . '|snapshot' ), 'stream_id' => $identity['stream_id'],
		),
	);
}

function p3_insert_descriptor( GHCA_ACD_WPDB_Archive_Artifact_Repository $repository, array $identity, string $key, string $kind, array $meta, string $seed ): array {
	$documents = p3_descriptor( $identity, $key, $kind, $meta['byte_count'], $meta['content_digest'], $seed );
	return $repository->insert_descriptor( $documents['descriptor'], $documents['binding'] );
}

// Root/public overlap and malformed-key rejection leave the root unchanged.
$root = p3_new_root();
p3_expect_store_failure( static function () use ( $root ) {
	new GHCA_ACD_Private_Archive_Artifact_Store( $root, array( ABSPATH ) );
}, 'orphan_cursor_key_invalid', 'ORPHAN-CURSOR-HMAC-KEY-ABSENT' );
p3_expect_store_failure( static function () use ( $root ) {
	new GHCA_ACD_Private_Archive_Artifact_Store( $root, array( ABSPATH ), 'not-a-32-byte-lowercase-hex-key' );
}, 'orphan_cursor_key_invalid', 'ORPHAN-CURSOR-HMAC-KEY-INVALID' );
p3_expect_store_failure( static function () use ( $root ) {
	new GHCA_ACD_Private_Archive_Artifact_Store( $root, array( $root ), GHCA_P3_CURSOR_HMAC_TEST_KEY );
}, 'artifact_root_public', 'ARTIFACT-PUBLIC-ROOT-REJECTED' );
$store = p3_store( $root );
$empty_source = p3_write_source( $root, 'empty-source.bin', '' );
$before = p3_tree_fingerprint( $root );
p3_expect_store_failure( static function () use ( $store, $empty_source ) {
	$store->write_staging( '../escape.part', $empty_source, 'certificate' );
}, 'artifact_key_invalid', 'ARTIFACT-TRAVERSAL-REJECTED' );
archive_check( $before === p3_tree_fingerprint( $root ), 'ARTIFACT-TRAVERSAL-NO-RESIDUE leaves the isolated root unchanged' );
rewind( $empty_source );
p3_expect_store_failure( static function () use ( $store, $empty_source ) {
	$store->write_staging( 'C:\\absolute\\escape.part', $empty_source, 'certificate' );
}, 'artifact_key_invalid', 'ARTIFACT-ABSOLUTE-PATH-REJECTED' );
archive_check( $before === p3_tree_fingerprint( $root ), 'ARTIFACT-ABSOLUTE-PATH-NO-RESIDUE leaves the isolated root unchanged' );
$invalid_keys = array(
	'ARTIFACT-UNC-PATH-REJECTED' => '\\\\server\\share\\artifact.part',
	'ARTIFACT-MIXED-SEPARATOR-REJECTED' => 'staging/' . p3_storage_id( 'mixed' ) . '\\escape.part',
	'ARTIFACT-CONTROL-CHARACTER-REJECTED' => "staging/" . p3_storage_id( 'control' ) . "/bad\x01.part",
	'ARTIFACT-DOT-SEGMENT-REJECTED' => 'staging/./artifact.part',
);
foreach ( $invalid_keys as $case_name => $invalid_key ) {
	rewind( $empty_source );
	p3_expect_store_failure( static function () use ( $store, $empty_source, $invalid_key ) {
		$store->write_staging( $invalid_key, $empty_source, 'certificate' );
	}, 'artifact_key_invalid', $case_name );
}
archive_check( $before === p3_tree_fingerprint( $root ), 'ARTIFACT-MALFORMED-KEYS-NO-RESIDUE leave the isolated root unchanged' );
p3_expect_store_failure( static function () use ( $store ) {
	$identity = p3_identity( 'invalid-identifier' );
	$identity['artifact_id'] = 'UPPERCASE';
	$store->create_staging( $identity );
}, 'artifact_key_invalid', 'ARTIFACT-INVALID-IDENTIFIER-REJECTED' );
fclose( $empty_source );

// Symlink components are rejected wherever the OS permits creating one.
$link_root = p3_new_root();
$link_store = p3_store( $link_root );
$link_identity = p3_identity( 'symlink' );
mkdir( $link_root . DIRECTORY_SEPARATOR . 'staging', 0700 );
$link_target = $link_root . DIRECTORY_SEPARATOR . 'link-target';
mkdir( $link_target, 0700 );
$link_path = $link_root . DIRECTORY_SEPARATOR . 'staging' . DIRECTORY_SEPARATOR . $link_identity['tenant_id'];
$link_created = @symlink( $link_target, $link_path );
if ( $link_created ) {
	p3_expect_store_failure( static function () use ( $link_store, $link_identity ) { $link_store->create_staging( $link_identity ); }, 'artifact_symlink_rejected', 'ARTIFACT-SYMLINK-ESCAPE-REJECTED' );
} else {
	archive_check( true, 'ARTIFACT-SYMLINK-ESCAPE-REJECTED skipped because this OS account cannot create symlinks' );
}

// Streamed certificate, exact reuse, mismatch rejection, and immutable bytes.
$identity = p3_identity( 'certificate' );
$certificate_source = $root . DIRECTORY_SEPARATOR . 'certificate-source.pdf';
p3_make_pdf_file( $certificate_source, 2 * 1024 * 1024 );
$certificate = p3_stage_file( $store, $identity, 'certificate', $certificate_source );
$certificate_key = $store->committed_key( $identity, 'certificate' );
$certificate_commit = $store->commit( $certificate['staging_key'], $certificate_key, 'certificate', $certificate['byte_count'], $certificate['content_digest'] );
$opened = $store->open_committed( $certificate_key, 'certificate', $certificate['byte_count'], $certificate['content_digest'] );
$opened_hash = hash_init( 'sha256' );
while ( ! feof( $opened ) ) {
	$chunk = fread( $opened, GHCA_ACD_Private_Archive_Artifact_Store::CHUNK_BYTES );
	if ( '' !== $chunk ) { hash_update( $opened_hash, $chunk ); }
}
fclose( $opened );
archive_check( ! $certificate_commit['reused'] && $certificate['content_digest'] === hash_final( $opened_hash ), 'ARTIFACT-CERTIFICATE-STREAMED writes, read-backs, and opens in bounded chunks' );

$certificate_repeat = p3_stage_file( $store, $identity, 'certificate', $certificate_source );
$repeat_commit = $store->commit( $certificate_repeat['staging_key'], $certificate_key, 'certificate', $certificate_repeat['byte_count'], $certificate_repeat['content_digest'] );
archive_check( $repeat_commit['reused'] && ! $repeat_commit['staging_cleanup_pending'], 'ARTIFACT-COMMIT-EXISTING-EXACT-REUSED returns idempotent success and cleans staging' );

$different_source = $root . DIRECTORY_SEPARATOR . 'certificate-different.pdf';
file_put_contents( $different_source, p3_pdf( 'different-valid-bytes' ) );
$different = p3_stage_file( $store, $identity, 'certificate', $different_source );
$committed_path = p3_path( $root, $certificate_key );
$immutable_before = hash_file( 'sha256', $committed_path );
p3_expect_store_failure( static function () use ( $store, $different, $certificate_key ) {
	$store->commit( $different['staging_key'], $certificate_key, 'certificate', $different['byte_count'], $different['content_digest'] );
}, 'artifact_immutable_mismatch', 'ARTIFACT-COMMIT-EXISTING-MISMATCH' );
archive_check( $immutable_before === hash_file( 'sha256', $committed_path ), 'ARTIFACT-COMMIT-NEVER-OVERWRITES preserves the original committed bytes' );
$different_stream = fopen( $different_source, 'rb' );
p3_expect_store_failure( static function () use ( $store, $different, $different_stream ) {
	$store->write_staging( $different['staging_key'], $different_stream, 'certificate' );
}, 'artifact_staging_collision', 'ARTIFACT-STAGING-NEVER-OVERWRITES' );
fclose( $different_stream );

// Wrong/truncated media and the certificate byte ceiling fail with retained staging only.
$bad_identity = p3_identity( 'bad-media' );
$bad_key = $store->create_staging( $bad_identity );
$bad_source = p3_write_source( $root, 'bad-source.bin', 'not-a-pdf' );
p3_expect_store_failure( static function () use ( $store, $bad_key, $bad_source ) {
	$store->write_staging( $bad_key, $bad_source, 'certificate' );
}, 'artifact_media_invalid', 'ARTIFACT-WRONG-TYPE-REJECTED' );
fclose( $bad_source );
archive_check( is_file( p3_path( $root, $bad_key ) ), 'ARTIFACT-WRONG-TYPE-RESIDUE stays only as a reportable staging object inside the test root' );
$truncated_key = $store->create_staging( p3_identity( 'truncated-pdf' ) );
$truncated_source = p3_write_source( $root, 'truncated-source.pdf', substr( p3_pdf( 'truncated' ), 0, -7 ) );
p3_expect_store_failure( static function () use ( $store, $truncated_key, $truncated_source ) {
	$store->write_staging( $truncated_key, $truncated_source, 'packet' );
}, 'artifact_media_invalid', 'ARTIFACT-TRUNCATED-PDF-REJECTED' );
fclose( $truncated_source );

$xref_bad_key = $store->create_staging( p3_identity( 'xref-token-bad' ) );
$xref_bad_header = "%PDF-1.4\n";
$xref_bad_offset = strlen( $xref_bad_header );
$xref_bad_source = p3_write_source( $root, 'xref-token-bad.pdf', $xref_bad_header . "xrefNOT_A_TABLE\nstartxref\n{$xref_bad_offset}\n%%EOF\n" );
p3_expect_store_failure( static function () use ( $store, $xref_bad_key, $xref_bad_source ) {
	$store->write_staging( $xref_bad_key, $xref_bad_source, 'packet' );
}, 'artifact_media_invalid', 'ARTIFACT-PDF-XREF-TOKEN-REJECTED' );
fclose( $xref_bad_source );

$xref_classic_key = $store->create_staging( p3_identity( 'xref-classic-valid' ) );
$xref_classic_bytes = p3_pdf( 'classic-valid' );
$xref_classic_source = p3_write_source( $root, 'xref-classic-valid.pdf', $xref_classic_bytes );
$xref_classic_meta = $store->write_staging( $xref_classic_key, $xref_classic_source, 'packet' );
fclose( $xref_classic_source );
archive_check( strlen( $xref_classic_bytes ) === $xref_classic_meta['byte_count'], 'ARTIFACT-PDF-CLASSIC-XREF-ACCEPTED preserves a complete xref token followed by a line ending' );

$xref_stream_key = $store->create_staging( p3_identity( 'xref-stream-valid' ) );
$xref_stream_bytes = p3_xref_stream_pdf();
$xref_stream_source = p3_write_source( $root, 'xref-stream-valid.pdf', $xref_stream_bytes );
$xref_stream_meta = $store->write_staging( $xref_stream_key, $xref_stream_source, 'packet' );
fclose( $xref_stream_source );
archive_check( strlen( $xref_stream_bytes ) === $xref_stream_meta['byte_count'], 'ARTIFACT-PDF-XREF-STREAM-ACCEPTED preserves bounded /Type /XRef stream support' );

$oversize_path = $root . DIRECTORY_SEPARATOR . 'oversize-source.bin';
$oversize = fopen( $oversize_path, 'w+b' );
$one_mib = str_repeat( 'Z', 1024 * 1024 );
for ( $i = 0; $i < 16; $i++ ) { fwrite( $oversize, $one_mib ); }
fwrite( $oversize, 'Z' );
rewind( $oversize );
$oversize_key = $store->create_staging( p3_identity( 'oversize' ) );
p3_expect_store_failure( static function () use ( $store, $oversize_key, $oversize ) {
	$store->write_staging( $oversize_key, $oversize, 'certificate' );
}, 'artifact_size_exceeded', 'ARTIFACT-CERTIFICATE-SIZE-CEILING' );
fclose( $oversize );

// Ledger may use its complete approved 8 MiB ceiling for canonical validation.
$ledger_identity = p3_identity( 'ledger' );
$ledger_chunks = array_fill( 0, 32, '' );
$empty_ledger = GHCA_ACD_Archive_Canonical_JSON::encode( array( 'payload_chunks' => $ledger_chunks, 'schema_version' => 1 ) );
$remaining_ledger_bytes = GHCA_ACD_Private_Archive_Artifact_Store::MAX_BYTES['ledger'] - strlen( $empty_ledger );
foreach ( $ledger_chunks as $index => $unused ) {
	$chunk_length = min( 262144, $remaining_ledger_bytes );
	$ledger_chunks[ $index ] = str_repeat( 'L', $chunk_length );
	$remaining_ledger_bytes -= $chunk_length;
}
$ledger_bytes = GHCA_ACD_Archive_Canonical_JSON::encode_bounded( array( 'payload_chunks' => $ledger_chunks, 'schema_version' => 1 ), GHCA_ACD_Private_Archive_Artifact_Store::MAX_BYTES['ledger'] );
$ledger_source = $root . DIRECTORY_SEPARATOR . 'ledger-source.json';
file_put_contents( $ledger_source, $ledger_bytes );
$ledger = p3_stage_file( $store, $ledger_identity, 'ledger', $ledger_source );
$ledger_key = $store->committed_key( $ledger_identity, 'ledger' );
$store->commit( $ledger['staging_key'], $ledger_key, 'ledger', $ledger['byte_count'], $ledger['content_digest'] );
archive_check( GHCA_ACD_Private_Archive_Artifact_Store::MAX_BYTES['ledger'] === $ledger['byte_count'], 'ARTIFACT-LEDGER-8M-CANONICAL accepts the exact canonical ledger ceiling' );

// A full 64 MiB packet is never buffered by the artifact store.
$packet_identity = p3_identity( 'packet' );
$packet_source = $root . DIRECTORY_SEPARATOR . 'packet-source.pdf';
p3_make_pdf_file( $packet_source, GHCA_ACD_Private_Archive_Artifact_Store::MAX_BYTES['packet'] );
$memory_before = memory_get_peak_usage( true );
$packet = p3_stage_file( $store, $packet_identity, 'packet', $packet_source );
$packet_key = $store->committed_key( $packet_identity, 'packet' );
$store->commit( $packet['staging_key'], $packet_key, 'packet', $packet['byte_count'], $packet['content_digest'] );
$memory_delta = memory_get_peak_usage( true ) - $memory_before;
archive_check( GHCA_ACD_Private_Archive_Artifact_Store::MAX_BYTES['packet'] === $packet['byte_count'] && $memory_delta <= 8 * 1024 * 1024, 'ARTIFACT-PACKET-64M-BOUNDED-MEMORY commits the ceiling with at most 8 MiB additional peak memory' );
archive_check( is_file( p3_path( $root, $packet_key ) ), 'ARTIFACT-PDF-BOUNDED-HEADER-TAIL-XREF validates a 64 MiB PDF by bounded seek windows' );

// Crash points: before write, after write, before commit, and after hard-link commit.
$crash_empty_key = $store->create_staging( p3_identity( 'crash-empty' ) );
archive_check( 0 === filesize( p3_path( $root, $crash_empty_key ) ), 'ARTIFACT-CRASH-BEFORE-STAGING-WRITE leaves one empty staging orphan' );
$crash_identity = p3_identity( 'crash-written' );
$crash_source = $root . DIRECTORY_SEPARATOR . 'crash-source.pdf';
file_put_contents( $crash_source, p3_pdf( 'crash-safe' ) );
$crash_written = p3_stage_file( $store, $crash_identity, 'packet', $crash_source );
archive_check( is_file( p3_path( $root, $crash_written['staging_key'] ) ), 'ARTIFACT-CRASH-AFTER-STAGING-WRITE leaves verified staging for reconciliation' );
archive_check( ! file_exists( p3_path( $root, $store->committed_key( $crash_identity, 'packet' ) ) ), 'ARTIFACT-COMMIT-CRASH-BEFORE-LINK leaves committed identity absent' );

$link_identity = p3_identity( 'crash-link' );
$link_source = $root . DIRECTORY_SEPARATOR . 'crash-link-source.pdf';
file_put_contents( $link_source, p3_pdf( 'hard-link-crash' ) );
$link_stage = p3_stage_file( $store, $link_identity, 'packet', $link_source );
$link_key = $store->committed_key( $link_identity, 'packet' );
$link_committed_path = p3_path( $root, $link_key );
if ( ! is_dir( dirname( $link_committed_path ) ) ) { mkdir( dirname( $link_committed_path ), 0700, true ); }
if ( ! link( p3_path( $root, $link_stage['staging_key'] ), $link_committed_path ) ) { throw new RuntimeException( 'test hard link failed' ); }
archive_check( is_file( p3_path( $root, $link_stage['staging_key'] ) ) && is_file( $link_committed_path ), 'ARTIFACT-COMMIT-CRASH-AFTER-LINK preserves both names after the immutable commit point' );
$recovered_link = $store->commit( $link_stage['staging_key'], $link_key, 'packet', $link_stage['byte_count'], $link_stage['content_digest'] );
archive_check( $recovered_link['reused'] && ! file_exists( p3_path( $root, $link_stage['staging_key'] ) ), 'ARTIFACT-COMMIT-CRASH-BEFORE-STAGING-UNLINK recovers by exact reuse and cleanup' );

$collision_identity = p3_identity( 'unsafe-collision' );
$collision_stage = p3_stage_file( $store, $collision_identity, 'packet', $crash_source );
$collision_key = $store->committed_key( $collision_identity, 'packet' );
$collision_path = p3_path( $root, $collision_key );
if ( ! is_dir( dirname( $collision_path ) ) ) { mkdir( dirname( $collision_path ), 0700, true ); }
mkdir( $collision_path, 0700 );
p3_expect_store_failure( static function () use ( $store, $collision_stage, $collision_key ) {
	$store->commit( $collision_stage['staging_key'], $collision_key, 'packet', $collision_stage['byte_count'], $collision_stage['content_digest'] );
}, 'artifact_commit_collision', 'ARTIFACT-COMMIT-UNSAFE-OBJECT-COLLISION' );
archive_check( is_dir( $collision_path ) && is_file( p3_path( $root, $collision_stage['staging_key'] ) ), 'ARTIFACT-COMMIT-COLLISION-NO-OVERWRITE preserves both existing objects' );

// Orphan exact-reference, staging classification, younger safety window, and report-only immutability.
ghca_persist_fresh_schema( $wpdb );
$orphan_root = p3_new_root();
$orphan_store = p3_store( $orphan_root );
$orphan_repo = new GHCA_ACD_WPDB_Archive_Artifact_Repository( $wpdb );
$orphan_clock = new GHCA_Persist_Fixed_Clock( '2026-07-18T12:00:00Z' );
$orphan_identity = p3_identity( 'orphan-protected' );
$orphan_source = $orphan_root . DIRECTORY_SEPARATOR . 'orphan-source.pdf';
file_put_contents( $orphan_source, p3_pdf( 'orphan' ) );
$orphan_stage_same_id = $orphan_store->create_staging( $orphan_identity );
$source_handle = fopen( $orphan_source, 'rb' );
$orphan_store->write_staging( $orphan_stage_same_id, $source_handle, 'packet' );
fclose( $source_handle );
$orphan_committed_stage = p3_stage_file( $orphan_store, $orphan_identity, 'packet', $orphan_source );
$orphan_key = $orphan_store->committed_key( $orphan_identity, 'packet' );
$orphan_store->commit( $orphan_committed_stage['staging_key'], $orphan_key, 'packet', $orphan_committed_stage['byte_count'], $orphan_committed_stage['content_digest'] );
p3_insert_descriptor( $orphan_repo, $orphan_identity, $orphan_key, 'packet', $orphan_committed_stage, 'orphan-protected' );
touch( p3_path( $orphan_root, $orphan_key ), strtotime( '2026-07-16T12:00:00Z' ) );
touch( p3_path( $orphan_root, $orphan_stage_same_id ), strtotime( '2026-07-16T12:00:00Z' ) );
$young_key = $orphan_store->create_staging( p3_identity( 'orphan-young' ) );
touch( p3_path( $orphan_root, $young_key ), strtotime( '2026-07-18T11:00:00Z' ) );
$orphan_reconciler = new GHCA_ACD_Archive_Orphan_Reconciler( $orphan_store, $orphan_repo, $orphan_clock );
$tree_before = p3_tree_fingerprint( $orphan_root );
$orphan_result = $orphan_reconciler->reconcile();
$classifications = array_column( $orphan_result['results'], 'classification', 'logical_key' );
archive_check( 'referenced_protected' === $classifications[ $orphan_key ], 'ORPHAN-EXACT-REFERENCE-PROTECTED protects exact artifact ID and storage key' );
archive_check( 'orphan_staging_unreferenced' === $classifications[ $orphan_stage_same_id ], 'ORPHAN-STAGING-SAME-ID-DIFFERENT-KEY-UNREFERENCED follows the approved staging rule' );
archive_check( ! array_key_exists( $young_key, $classifications ), 'ORPHAN-YOUNGER-THAN-SAFETY-WINDOW-IGNORED' );
archive_check( $tree_before === p3_tree_fingerprint( $orphan_root ), 'ORPHAN-REPORT-ONLY-NO-MUTATION leaves every candidate byte and name unchanged' );

// A committed candidate contradicting the retained key aborts without residue.
ghca_persist_fresh_schema( $wpdb );
$mismatch_root = p3_new_root();
$mismatch_store = p3_store( $mismatch_root );
$mismatch_repo = new GHCA_ACD_WPDB_Archive_Artifact_Repository( $wpdb );
$mismatch_identity = p3_identity( 'orphan-mismatch' );
$mismatch_source = $mismatch_root . DIRECTORY_SEPARATOR . 'source.pdf';
file_put_contents( $mismatch_source, p3_pdf( 'mismatch' ) );
$mismatch_meta = p3_stage_file( $mismatch_store, $mismatch_identity, 'packet', $mismatch_source );
$mismatch_key = $mismatch_store->committed_key( $mismatch_identity, 'packet' );
$mismatch_store->commit( $mismatch_meta['staging_key'], $mismatch_key, 'packet', $mismatch_meta['byte_count'], $mismatch_meta['content_digest'] );
$other_identity = $mismatch_identity;
$other_identity['tenant_id'] = p3_storage_id( 'other-tenant' );
$other_key = $mismatch_store->committed_key( $other_identity, 'packet' );
p3_insert_descriptor( $mismatch_repo, $mismatch_identity, $other_key, 'packet', $mismatch_meta, 'orphan-mismatch' );
touch( p3_path( $mismatch_root, $mismatch_key ), strtotime( '2026-07-16T12:00:00Z' ) );
$mismatch_before = p3_tree_fingerprint( $mismatch_root );
p3_expect_store_failure( static function () use ( $mismatch_store, $mismatch_repo, $orphan_clock ) {
	( new GHCA_ACD_Archive_Orphan_Reconciler( $mismatch_store, $mismatch_repo, $orphan_clock ) )->reconcile();
}, 'orphan_reference_binding_mismatch', 'ORPHAN-COMMITTED-REFERENCE-BINDING-MISMATCH' );
archive_check( $mismatch_before === p3_tree_fingerprint( $mismatch_root ), 'ORPHAN-BINDING-MISMATCH-NO-MUTATION' );

// Database and retained-row failures abort; a reference inserted at recheck wins the race.
ghca_persist_fresh_schema( $wpdb );
$failure_root = p3_new_root();
$failure_store = p3_store( $failure_root );
$failure_identity = p3_identity( 'orphan-db-failure' );
$failure_source = $failure_root . DIRECTORY_SEPARATOR . 'source.pdf';
file_put_contents( $failure_source, p3_pdf( 'database-failure' ) );
$failure_meta = p3_stage_file( $failure_store, $failure_identity, 'packet', $failure_source );
$failure_key = $failure_store->committed_key( $failure_identity, 'packet' );
$failure_store->commit( $failure_meta['staging_key'], $failure_key, 'packet', $failure_meta['byte_count'], $failure_meta['content_digest'] );
touch( p3_path( $failure_root, $failure_key ), strtotime( '2026-07-16T12:00:00Z' ) );
archive_check( is_file( p3_path( $failure_root, $failure_key ) ), 'ARTIFACT-COMMIT-CRASH-BEFORE-DESCRIPTOR-TRANSACTION leaves one immutable committed orphan' );
$db_proxy = new GHCA_Persist_DB_Proxy( $wpdb );
$db_proxy->add_hook( 'get_row', 'ghca_acd_archive_artifacts', 'fail' );
$failure_repo = new GHCA_ACD_WPDB_Archive_Artifact_Repository( $db_proxy );
$failure_before = p3_tree_fingerprint( $failure_root );
p3_expect_store_failure( static function () use ( $failure_store, $failure_repo, $orphan_clock ) {
	( new GHCA_ACD_Archive_Orphan_Reconciler( $failure_store, $failure_repo, $orphan_clock ) )->reconcile();
}, 'orphan_reference_recheck_failed', 'ORPHAN-DATABASE-FAILURE-ABORTS' );
archive_check( $failure_before === p3_tree_fingerprint( $failure_root ), 'ORPHAN-DATABASE-FAILURE-NO-MUTATION' );

$real_repo = new GHCA_ACD_WPDB_Archive_Artifact_Repository( $wpdb );
$stored_failure_descriptor = p3_insert_descriptor( $real_repo, $failure_identity, $failure_key, 'packet', $failure_meta, 'orphan-db-failure' );
$artifact_table = $wpdb->prefix . 'ghca_acd_archive_artifacts';
ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$artifact_table} SET content_digest = %s WHERE artifact_id = %s", str_repeat( 'z', 64 ), $failure_identity['artifact_id'] ), 'tamper retained artifact descriptor' );
p3_expect_store_failure( static function () use ( $failure_store, $real_repo, $orphan_clock ) {
	( new GHCA_ACD_Archive_Orphan_Reconciler( $failure_store, $real_repo, $orphan_clock ) )->reconcile();
}, 'orphan_reference_recheck_failed', 'ORPHAN-RETAINED-DESCRIPTOR-INTEGRITY-ABORTS' );
archive_check( $failure_before === p3_tree_fingerprint( $failure_root ), 'ORPHAN-RETAINED-INTEGRITY-NO-MUTATION' );

ghca_persist_fresh_schema( $wpdb );
$race_root = p3_new_root();
$race_store = p3_store( $race_root );
$race_identity = p3_identity( 'orphan-recheck-race' );
$race_source = $race_root . DIRECTORY_SEPARATOR . 'source.pdf';
file_put_contents( $race_source, p3_pdf( 'recheck-race' ) );
$race_meta = p3_stage_file( $race_store, $race_identity, 'packet', $race_source );
$race_key = $race_store->committed_key( $race_identity, 'packet' );
$race_store->commit( $race_meta['staging_key'], $race_key, 'packet', $race_meta['byte_count'], $race_meta['content_digest'] );
touch( p3_path( $race_root, $race_key ), strtotime( '2026-07-16T12:00:00Z' ) );
$race_connection = ghca_persist_new_connection();
$race_insert_repo = new GHCA_ACD_WPDB_Archive_Artifact_Repository( $race_connection );
$race_proxy = new GHCA_Persist_DB_Proxy( $wpdb );
$race_proxy->add_hook( 'get_row', 'ghca_acd_archive_artifacts', static function () use ( $race_insert_repo, $race_identity, $race_key, $race_meta ) {
	p3_insert_descriptor( $race_insert_repo, $race_identity, $race_key, 'packet', $race_meta, 'orphan-recheck-race' );
}, 1 );
$race_repo = new GHCA_ACD_WPDB_Archive_Artifact_Repository( $race_proxy );
$race_result = ( new GHCA_ACD_Archive_Orphan_Reconciler( $race_store, $race_repo, $orphan_clock ) )->reconcile();
archive_check( 'referenced_protected' === $race_result['results'][0]['classification'], 'ORPHAN-DATABASE-RECHECK-PREVENTS-RACE-DISPOSITION' );

// A single large directory is streamed, and untrusted cursor state is rejected.
ghca_persist_fresh_schema( $wpdb );
$large_root = p3_new_root();
$large_store = p3_store( $large_root );
$large_identity = p3_identity( 'large-directory' );
$large_first_key = $large_store->create_staging( $large_identity );
$large_directory = dirname( p3_path( $large_root, $large_first_key ) );
$large_cutoff = strtotime( '2026-07-17T12:00:00Z' );
for ( $i = 0; $i < 1200; $i++ ) {
	$filename = substr( hash( 'sha256', 'large-directory|' . $i ), 0, 32 ) . '.part';
	$path = $large_directory . DIRECTORY_SEPARATOR . $filename;
	if ( ! file_exists( $path ) ) {
		file_put_contents( $path, 'x' );
	}
	touch( $path, strtotime( '2026-07-16T12:00:00Z' ) + $i );
}
touch( p3_path( $large_root, $large_first_key ), strtotime( '2026-07-16T11:59:59Z' ) );
$large_memory_before = memory_get_usage( true );
$large_first = $large_store->enumerate_candidates( $large_cutoff, 1000 );
$large_memory_delta = memory_get_usage( true ) - $large_memory_before;
archive_check( 1000 === $large_first['scanned'] && array() === $large_first['candidates']
	&& $large_first['truncated'] && is_array( $large_first['next_cursor'] ) && $large_memory_delta <= 8 * 1024 * 1024,
	'ORPHAN-LARGE-DIRECTORY-BOUNDED streams at most 1,000 entries without materializing or emitting an incomplete page' );

$vector_payload = array(
	'cursor_schema_version' => 1,
	'root_digest'          => str_repeat( '0', 64 ),
	'older_than_epoch'     => 1,
	'after'                => null,
	'area_index'           => 0,
	'stack'                => array(),
	'selected'             => array(),
);
$vector_canonical = GHCA_ACD_Archive_Canonical_JSON::encode_bounded( $vector_payload, 32768 );
$vector_hmac = hash_hmac(
	'sha256',
	"ghca-orphan-cursor-hmac-v1\n" . $vector_canonical,
	hex2bin( GHCA_P3_CURSOR_HMAC_TEST_KEY )
);
archive_check(
	'{"after":null,"area_index":0,"cursor_schema_version":1,"older_than_epoch":1,"root_digest":"' . str_repeat( '0', 64 ) . '","selected":[],"stack":[]}' === $vector_canonical
	&& 'c6a92f335e3519df02379e2ef55ee52a2a29da75504af00dfb8a36aef53c1f5b' === $vector_hmac,
	'ORPHAN-CURSOR-HMAC-FIXED-VECTOR independently reproduces the fixed domain-separated canonical HMAC vector'
);

$issued_cursor = $large_first['next_cursor'];
$issued_payload = $issued_cursor;
$issued_hmac = $issued_payload['cursor_hmac'];
unset( $issued_payload['cursor_hmac'] );
$independent_issued_hmac = hash_hmac(
	'sha256',
	"ghca-orphan-cursor-hmac-v1\n" . GHCA_ACD_Archive_Canonical_JSON::encode_bounded( $issued_payload, 32768 ),
	hex2bin( GHCA_P3_CURSOR_HMAC_TEST_KEY )
);
archive_check(
	is_string( $issued_hmac ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $issued_hmac ) && hash_equals( $independent_issued_hmac, $issued_hmac ),
	'ORPHAN-CURSOR-HMAC-ISSUED-VECTOR authenticates every field in a production-issued cursor'
);

$same_key_store = p3_store( $large_root );
$valid_continuation = $same_key_store->enumerate_candidates( null, 1000, $issued_cursor );
$valid_continuation_ordered = true;
for ( $i = 1; $i < count( $valid_continuation['candidates'] ); $i++ ) {
	$prior = $valid_continuation['candidates'][ $i - 1 ];
	$current = $valid_continuation['candidates'][ $i ];
	$valid_continuation_ordered = $valid_continuation_ordered
		&& ( $prior['mtime'] < $current['mtime'] || ( $prior['mtime'] === $current['mtime'] && strcmp( $prior['logical_key'], $current['logical_key'] ) < 0 ) );
}
archive_check(
	$valid_continuation_ordered
	&& ( null === $valid_continuation['next_cursor'] || $large_cutoff === $valid_continuation['next_cursor']['older_than_epoch'] ),
	'ORPHAN-CURSOR-HMAC-VALID-CONTINUATION lets a second same-key store preserve the frozen cutoff and tuple order'
);

$cursor_proxy = new GHCA_Persist_DB_Proxy( $wpdb );
$descriptor_queries = 0;
$cursor_proxy->add_hook( 'get_row', 'ghca_acd_archive_artifacts', static function () use ( &$descriptor_queries ) {
	$descriptor_queries++;
}, 100 );
$cursor_repository = new GHCA_ACD_WPDB_Archive_Artifact_Repository( $cursor_proxy );
$cursor_reconciler = new GHCA_ACD_Archive_Orphan_Reconciler( $large_store, $cursor_repository, $orphan_clock );

$cutoff_cursor = $issued_cursor;
$cutoff_cursor['older_than_epoch']++;
p3_expect_cursor_auth_failure( $cursor_reconciler, $cutoff_cursor, $wpdb, $large_root, $descriptor_queries, 'ORPHAN-CURSOR-HMAC-CUTOFF-TAMPER-REJECTED' );

$after_cursor = $issued_cursor;
$after_cursor['after'] = array( 'mtime' => 0, 'logical_key' => $large_first_key );
p3_expect_cursor_auth_failure( $cursor_reconciler, $after_cursor, $wpdb, $large_root, $descriptor_queries, 'ORPHAN-CURSOR-HMAC-AFTER-TAMPER-REJECTED' );

$position_cursor = $issued_cursor;
$position_index = count( $position_cursor['stack'] ) - 1;
if ( $position_index < 0 ) {
	throw new RuntimeException( 'cursor authentication fixture has no active traversal frame' );
}
$position_cursor['stack'][ $position_index ]['next_position']++;
p3_expect_cursor_auth_failure( $cursor_reconciler, $position_cursor, $wpdb, $large_root, $descriptor_queries, 'ORPHAN-CURSOR-HMAC-POSITION-TAMPER-REJECTED' );

$selected_cursor = $issued_cursor;
$selected_index = count( $selected_cursor['selected'] ) - 1;
if ( $selected_index < 0 ) {
	throw new RuntimeException( 'cursor authentication fixture has no buffered candidate' );
}
$selected_cursor['selected'][ $selected_index ]['mtime']++;
p3_expect_cursor_auth_failure( $cursor_reconciler, $selected_cursor, $wpdb, $large_root, $descriptor_queries, 'ORPHAN-CURSOR-HMAC-SELECTED-TAMPER-REJECTED' );

$wrong_key_store = p3_store( $large_root, GHCA_P3_CURSOR_HMAC_WRONG_KEY );
$wrong_key_reconciler = new GHCA_ACD_Archive_Orphan_Reconciler( $wrong_key_store, $cursor_repository, $orphan_clock );
p3_expect_cursor_auth_failure( $wrong_key_reconciler, $issued_cursor, $wpdb, $large_root, $descriptor_queries, 'ORPHAN-CURSOR-HMAC-WRONG-KEY-REJECTED' );

$rotation_restart = $wrong_key_store->enumerate_candidates( $large_cutoff, 1, null );
archive_check(
	is_array( $rotation_restart['next_cursor'] )
	&& $issued_cursor['cursor_hmac'] !== $rotation_restart['next_cursor']['cursor_hmac'],
	'ORPHAN-CURSOR-HMAC-ROTATION-RESTART invalidates the old cursor while a new null-cursor scan succeeds'
);

$non_exposure_error = null;
try {
	$wrong_key_store->enumerate_candidates( null, 1000, $issued_cursor );
} catch ( Throwable $error ) {
	$non_exposure_error = $error;
}
$issued_json = GHCA_ACD_Archive_Canonical_JSON::encode_bounded( $issued_cursor, 32768 );
$valid_result_json = GHCA_ACD_Archive_Canonical_JSON::encode_bounded( $valid_continuation, 32768 );
$non_exposure_text = null === $non_exposure_error ? '' : $non_exposure_error->getMessage();
archive_check(
	false === strpos( $issued_json, GHCA_P3_CURSOR_HMAC_TEST_KEY )
	&& false === strpos( $issued_json, hex2bin( GHCA_P3_CURSOR_HMAC_TEST_KEY ) )
	&& false === strpos( $valid_result_json, GHCA_P3_CURSOR_HMAC_TEST_KEY )
	&& false === strpos( $valid_result_json, hex2bin( GHCA_P3_CURSOR_HMAC_TEST_KEY ) )
	&& false === strpos( $non_exposure_text, GHCA_P3_CURSOR_HMAC_TEST_KEY )
	&& false === strpos( $non_exposure_text, hex2bin( GHCA_P3_CURSOR_HMAC_TEST_KEY ) ),
	'ORPHAN-CURSOR-HMAC-NOT-EXPOSED keeps both raw and hexadecimal keys out of cursors, results, and exception text'
);

p3_expect_store_failure( static function () use ( $large_store, $issued_cursor ) {
	$large_store->enumerate_candidates( 1, 1000, $issued_cursor );
}, 'orphan_cursor_invalid', 'ORPHAN-CURSOR-HMAC-CONTINUATION-CUTOFF-REJECTED' );
p3_expect_store_failure( static function () use ( $large_store ) {
	$large_store->enumerate_candidates( null, 1000, null );
}, 'orphan_cursor_invalid', 'ORPHAN-CURSOR-HMAC-FIRST-CUTOFF-REQUIRED' );

$wrong_root = p3_new_root();
$wrong_root_store = p3_store( $wrong_root );
p3_expect_store_failure( static function () use ( $wrong_root_store, $issued_cursor ) {
	$wrong_root_store->enumerate_candidates( null, 1000, $issued_cursor );
}, 'orphan_cursor_invalid', 'ORPHAN-CURSOR-AUTHENTICATED-WRONG-ROOT-REJECTED' );

$stale_cursor = $large_first['next_cursor'];
$stale_candidate = $stale_cursor['selected'][0];
touch( p3_path( $large_root, $stale_candidate['logical_key'] ), $stale_candidate['mtime'] + 1 );
p3_expect_store_failure( static function () use ( $large_store, $stale_cursor ) {
	$large_store->enumerate_candidates( null, 1000, $stale_cursor );
}, 'orphan_cursor_stale', 'ORPHAN-CURSOR-BUFFERED-CANDIDATE-REVALIDATED' );

// Result/scan bounds, frozen cutoff, forward progress, and global page order.
ghca_persist_fresh_schema( $wpdb );
$bounded_root = p3_new_root();
$bounded_store = p3_store( $bounded_root );
for ( $i = 0; $i < 205; $i++ ) {
	$key = $bounded_store->create_staging( p3_identity( 'bounded-' . $i ) );
	touch( p3_path( $bounded_root, $key ), strtotime( '2026-07-16T12:00:00Z' ) + $i );
}
$bounded_clock = new GHCA_Persist_Fixed_Clock( '2026-07-18T12:00:00Z' );
$bounded_reconciler = new GHCA_ACD_Archive_Orphan_Reconciler( $bounded_store, new GHCA_ACD_WPDB_Archive_Artifact_Repository( $wpdb ), $bounded_clock );
$bounded_before = p3_tree_fingerprint( $bounded_root );
$bounded_cursor = null;
$bounded_results = array();
$bounded_cursor_digests = array();
$bounded_calls = 0;
$bounded_incomplete_pages = 0;
$frozen_cutoff = null;
$bounded_page_cursor = null;
do {
	$bounded_result = $bounded_reconciler->reconcile( $bounded_cursor );
	$bounded_calls++;
	archive_check( $bounded_result['scanned'] <= 1000 && count( $bounded_result['results'] ) <= 100, 'ORPHAN-RESULT-BOUND call ' . $bounded_calls . ' preserves both ceilings' );
	if ( array() === $bounded_result['results'] && null !== $bounded_result['next_cursor'] ) {
		$bounded_incomplete_pages++;
	}
	foreach ( $bounded_result['results'] as $result ) {
		$bounded_results[] = $result;
	}
	if ( null === $bounded_page_cursor && array() !== $bounded_result['results'] && null !== $bounded_result['next_cursor'] ) {
		$bounded_page_cursor = $bounded_result['next_cursor'];
	}
	$bounded_cursor = $bounded_result['next_cursor'];
	if ( null !== $bounded_cursor ) {
		if ( null === $frozen_cutoff ) {
			$frozen_cutoff = $bounded_cursor['older_than_epoch'];
			$bounded_clock->set( '2026-07-30T12:00:00Z' );
		}
		archive_check( $frozen_cutoff === $bounded_cursor['older_than_epoch'], 'ORPHAN-CURSOR-CUTOFF-FROZEN continuation ' . $bounded_calls . ' retains the first-call cutoff' );
		$bounded_cursor_digests[] = hash( 'sha256', GHCA_ACD_Archive_Canonical_JSON::encode( $bounded_cursor ) );
	}
	if ( $bounded_calls > 12 ) {
		throw new RuntimeException( 'orphan continuation did not terminate within its expected bound' );
	}
} while ( null !== $bounded_cursor );

if ( null === $bounded_page_cursor || array() !== $bounded_page_cursor['stack'] || 0 !== $bounded_page_cursor['area_index'] ) {
	throw new RuntimeException( 'cursor authentication fixture has no completed-page cursor' );
}
$area_cursor = $bounded_page_cursor;
$area_cursor['area_index'] = 1;
$bounded_auth_reconciler = new GHCA_ACD_Archive_Orphan_Reconciler( $bounded_store, $cursor_repository, $bounded_clock );
p3_expect_cursor_auth_failure( $bounded_auth_reconciler, $area_cursor, $wpdb, $bounded_root, $descriptor_queries, 'ORPHAN-CURSOR-HMAC-AREA-TAMPER-REJECTED' );

$bounded_keys = array_column( $bounded_results, 'logical_key' );
archive_check( 205 === count( $bounded_results ) && $bounded_incomplete_pages >= 3 && $bounded_calls <= 9,
	'ORPHAN-TRUNCATED-SCAN-MAKES-PROGRESS completes every candidate through bounded multi-call traversals' );
archive_check( count( $bounded_cursor_digests ) === count( array_unique( $bounded_cursor_digests ) )
	&& count( $bounded_keys ) === count( array_unique( $bounded_keys ) ),
	'ORPHAN-TRUNCATED-SCAN-NO-DUPLICATE-PREFIX advances unique cursors and emits each logical key once' );
$stable_order = true;
for ( $i = 1; $i < count( $bounded_results ); $i++ ) {
	$prior = $bounded_results[ $i - 1 ];
	$current = $bounded_results[ $i ];
	$stable_order = $stable_order && ( $prior['mtime'] < $current['mtime'] || ( $prior['mtime'] === $current['mtime'] && strcmp( $prior['logical_key'], $current['logical_key'] ) < 0 ) );
}
archive_check( $stable_order, 'ORPHAN-STABLE-ORDER-ACROSS-PAGES preserves global mtime/logical-key order' );
archive_check( $bounded_before === p3_tree_fingerprint( $bounded_root ), 'ORPHAN-REPORT-ONLY-NO-MUTATION remains byte/name immutable across every continuation and page' );

// Cleanup refuses every target outside the exact generated test root.
$cleanup_target = dirname( $root );
p3_expect_store_failure( static function () use ( $cleanup_target ) { p3_cleanup_root( $cleanup_target ); }, 'test_cleanup_boundary_violation', 'TEST-CLEANUP-CANNOT-ESCAPE-ROOT' );
archive_check( is_dir( $root ), 'TEST-CLEANUP-BOUNDARY-NO-RESIDUE preserves the approved test root after rejection' );

foreach ( array_reverse( $p3_roots ) as $test_root ) {
	if ( is_dir( $test_root ) && ! is_link( $test_root ) ) {
		p3_cleanup_root( $test_root );
	}
}
archive_check( true, 'ARTIFACT-TEST-ROOT-CLEANUP removed only validated isolated roots' );

archive_finish();
