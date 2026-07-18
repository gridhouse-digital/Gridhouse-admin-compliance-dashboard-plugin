<?php

/** Private-local immutable artifact bytes. No WordPress or network access. */
final class GHCA_ACD_Private_Archive_Artifact_Store implements GHCA_ACD_Archive_Artifact_Store {
	const CHUNK_BYTES = 1048576;
	const PDF_TAIL_BYTES = 65536;
	const PDF_XREF_BYTES = 8192;
	const CURSOR_SCHEMA_VERSION = 1;
	const CURSOR_MAX_BYTES      = 32768;
	const CURSOR_MAX_DEPTH      = 5;
	const CURSOR_MAX_POSITION   = 10000000;
	const CURSOR_PAGE_SIZE      = 100;
	const CURSOR_RETAIN_LIMIT   = 101;
	const CURSOR_HMAC_DOMAIN    = "ghca-orphan-cursor-hmac-v1\n";
	const MAX_BYTES = array(
		'certificate' => 16777216,
		'ledger'      => 8388608,
		'packet'      => 67108864,
	);

	/** @var string */
	private $root;
	/** @var bool */
	private $windows;
	/** @var string Raw 32-byte cursor-authentication key. */
	private $cursor_hmac_key;

	/** @param array<int,string> $public_roots */
	public function __construct( string $private_root, array $public_roots, string $cursor_hmac_key = '' ) {
		$this->windows = '\\' === DIRECTORY_SEPARATOR;
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $cursor_hmac_key ) ) {
			throw $this->failure( 'orphan_cursor_key_invalid', 'The orphan cursor authentication key is invalid.' );
		}
		$raw_cursor_hmac_key = hex2bin( $cursor_hmac_key );
		if ( false === $raw_cursor_hmac_key || 32 !== strlen( $raw_cursor_hmac_key ) ) {
			throw $this->failure( 'orphan_cursor_key_invalid', 'The orphan cursor authentication key is invalid.' );
		}
		$this->cursor_hmac_key = $raw_cursor_hmac_key;
		if ( array() === $public_roots ) {
			throw $this->failure( 'artifact_root_invalid', 'At least one public document root must be injected.' );
		}
		if ( ! $this->is_absolute( $private_root ) || is_link( $private_root ) || ! is_dir( $private_root ) ) {
			throw $this->failure( 'artifact_root_invalid', 'The private artifact root is invalid.' );
		}
		$root = realpath( $private_root );
		if ( false === $root ) {
			throw $this->failure( 'artifact_root_invalid', 'The private artifact root is invalid.' );
		}
		$this->root = $this->normalize_path( $root );
		if ( ! $this->windows && ( fileperms( $root ) & 0077 ) !== 0 ) {
			throw $this->failure( 'artifact_permissions_unsafe', 'The private artifact root permissions are not restrictive.' );
		}
		foreach ( $public_roots as $public_root ) {
			if ( ! is_string( $public_root ) || ! $this->is_absolute( $public_root ) || is_link( $public_root ) || ! is_dir( $public_root ) ) {
				throw $this->failure( 'artifact_root_invalid', 'An injected public document root is invalid.' );
			}
			$public = realpath( $public_root );
			if ( false === $public ) {
				throw $this->failure( 'artifact_root_invalid', 'An injected public document root is invalid.' );
			}
			$public = $this->normalize_path( $public );
			if ( $this->within( $this->root, $public ) || $this->within( $public, $this->root ) ) {
				throw $this->failure( 'artifact_root_public', 'The private artifact root overlaps a public document root.' );
			}
		}
	}

	/** @param array<string,string> $identity */
	public function create_staging( array $identity ): string {
		$ids = $this->identity( $identity );
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$key = 'staging/' . implode( '/', $ids ) . '/' . bin2hex( random_bytes( 16 ) ) . '.part';
			$path = $this->path_for_new_key( $key );
			$handle = @fopen( $path, 'x+b' );
			if ( false === $handle ) {
				if ( file_exists( $path ) || is_link( $path ) ) {
					continue;
				}
				throw $this->failure( 'artifact_write_failed', 'The staging artifact could not be created.' );
			}
			@fclose( $handle );
			$this->restrict_file( $path );
			return $key;
		}
		throw $this->failure( 'artifact_staging_collision', 'A unique staging artifact could not be created.' );
	}

	/** @param array<string,string> $identity */
	public function committed_key( array $identity, string $kind ): string {
		$ids = $this->identity( $identity );
		$this->assert_kind( $kind );
		$extension = 'ledger' === $kind ? 'json' : 'pdf';
		return 'committed/' . implode( '/', $ids ) . '.' . $extension;
	}

	/** @param resource $source @return array<string,mixed> */
	public function write_staging( string $staging_key, $source, string $kind ): array {
		$this->assert_staging_key( $staging_key );
		$this->assert_kind( $kind );
		if ( ! is_resource( $source ) ) {
			throw $this->failure( 'artifact_write_failed', 'Artifact input must be a readable stream.' );
		}
		$path   = $this->path_for_existing_key( $staging_key );
		$handle = $this->open_regular( $path, 'r+b', 'artifact_write_failed' );
		$stat   = fstat( $handle );
		if ( ! is_array( $stat ) || 0 !== (int) $stat['size'] ) {
			@fclose( $handle );
			throw $this->failure( 'artifact_staging_collision', 'The staging artifact is not empty.' );
		}
		$count = 0;
		$hash  = hash_init( 'sha256' );
		try {
			while ( ! feof( $source ) ) {
				$chunk = @fread( $source, self::CHUNK_BYTES );
				if ( false === $chunk ) {
					throw $this->failure( 'artifact_write_failed', 'Artifact input could not be read.' );
				}
				if ( '' === $chunk ) {
					if ( feof( $source ) ) {
						break;
					}
					throw $this->failure( 'artifact_write_failed', 'Artifact input returned no progress.' );
				}
				$length = strlen( $chunk );
				if ( $count + $length > self::MAX_BYTES[ $kind ] ) {
					throw $this->failure( 'artifact_size_exceeded', 'The artifact exceeds its approved byte ceiling.' );
				}
				$this->write_all( $handle, $chunk );
				hash_update( $hash, $chunk );
				$count += $length;
			}
			if ( 0 === $count ) {
				throw $this->failure( 'artifact_media_invalid', 'The artifact is empty.' );
			}
			if ( ! @fflush( $handle ) ) {
				throw $this->failure( 'artifact_write_failed', 'The staging artifact could not be flushed.' );
			}
			if ( function_exists( 'fsync' ) && ! @fsync( $handle ) ) {
				throw $this->failure( 'artifact_write_failed', 'The staging artifact could not be synchronized.' );
			}
		} finally {
			@fclose( $handle );
		}
		$written_digest = hash_final( $hash );
		$verified       = $this->verify_path( $path, $kind, $count, $written_digest );
		return array(
			'staging_key'   => $staging_key,
			'byte_count'    => $verified['byte_count'],
			'content_digest' => $verified['content_digest'],
		);
	}

	/** @return array<string,mixed> */
	public function commit( string $staging_key, string $committed_key, string $kind, int $byte_count, string $sha256 ): array {
		$staging_ids   = $this->assert_staging_key( $staging_key );
		$committed_ids = $this->assert_committed_key( $committed_key, $kind );
		if ( array_slice( $staging_ids, 0, 4 ) !== $committed_ids ) {
			throw $this->failure( 'artifact_key_invalid', 'Staging and committed artifact identities do not match.' );
		}
		$this->assert_expected_bytes( $kind, $byte_count, $sha256 );
		$staging_path = $this->path_for_existing_key( $staging_key );
		$this->verify_path( $staging_path, $kind, $byte_count, $sha256 );
		$committed_path = $this->path_for_new_key( $committed_key );

		if ( file_exists( $committed_path ) || is_link( $committed_path ) ) {
			return $this->reuse_existing( $staging_path, $committed_path, $committed_key, $kind, $byte_count, $sha256 );
		}
		if ( ! function_exists( 'link' ) ) {
			throw $this->failure( 'artifact_atomic_commit_unsupported', 'Atomic hard-link commit is unavailable.' );
		}
		if ( ! @link( $staging_path, $committed_path ) ) {
			if ( file_exists( $committed_path ) || is_link( $committed_path ) ) {
				return $this->reuse_existing( $staging_path, $committed_path, $committed_key, $kind, $byte_count, $sha256 );
			}
			throw $this->failure( 'artifact_commit_failed', 'The immutable artifact could not be committed.' );
		}
		$this->restrict_file( $committed_path );
		try {
			$this->verify_path( $committed_path, $kind, $byte_count, $sha256 );
		} catch ( Throwable $error ) {
			throw $this->failure( 'artifact_immutable_mismatch', 'The committed artifact does not match the verified staging bytes.' );
		}
		$cleanup_pending = ! @unlink( $staging_path );
		return array(
			'committed_key'          => $committed_key,
			'byte_count'             => $byte_count,
			'content_digest'         => $sha256,
			'reused'                 => false,
			'staging_cleanup_pending' => $cleanup_pending,
		);
	}

	/** @return resource */
	public function open_committed( string $committed_key, string $kind, int $byte_count, string $sha256 ) {
		$this->assert_committed_key( $committed_key, $kind );
		$this->assert_expected_bytes( $kind, $byte_count, $sha256 );
		$path   = $this->path_for_existing_key( $committed_key );
		$handle = $this->open_regular( $path, 'rb', 'artifact_open_failed' );
		try {
			$this->verify_handle( $handle, $kind, $byte_count, $sha256 );
			if ( 0 !== @fseek( $handle, 0, SEEK_SET ) ) {
				throw $this->failure( 'artifact_open_failed', 'The committed artifact could not be rewound.' );
			}
			return $handle;
		} catch ( Throwable $error ) {
			@fclose( $handle );
			throw $error;
		}
	}

	/** @return array<string,mixed> */
	public function enumerate_candidates( ?int $older_than_epoch, int $limit = 1000, ?array $cursor = null ): array {
		if ( $limit < 1 || $limit > 1000 ) {
			throw $this->failure( 'orphan_scan_failed', 'The orphan scan bound is invalid.' );
		}
		if ( null === $cursor ) {
			if ( null === $older_than_epoch || $older_than_epoch < 0 ) {
				throw $this->failure( 'orphan_cursor_invalid', 'The orphan scan requires one explicit safety cutoff.' );
			}
			$state = $this->new_cursor_state( $older_than_epoch, null );
		} else {
			if ( null !== $older_than_epoch ) {
				throw $this->failure( 'orphan_cursor_invalid', 'The orphan continuation cutoff must come from its authenticated cursor.' );
			}
			$state = $this->load_cursor( $cursor );
		}

		$scanned   = 0;
		$iterators = array();
		while ( $scanned < $limit ) {
			if ( ! $this->ensure_cursor_frame( $state ) ) {
				return $this->complete_cursor_scan( $state, $scanned );
			}
			$depth = count( $state['stack'] ) - 1;
			if ( ! isset( $iterators[ $depth ] ) ) {
				$frame = $state['stack'][ $depth ];
				$path  = $this->revalidate_cursor_directory( $frame );
				try {
					$iterator = new DirectoryIterator( $path );
					$iterator->seek( $frame['next_position'] );
				} catch ( Throwable $error ) {
					throw $this->failure( 'orphan_cursor_stale', 'The orphan continuation position is stale.' );
				}
				$iterators[ $depth ] = $iterator;
			}

			$iterator = $iterators[ $depth ];
			while ( $iterator->valid() && $iterator->isDot() ) {
				$iterator->next();
				$state['stack'][ $depth ]['next_position']++;
			}
			if ( ! $iterator->valid() ) {
				array_pop( $state['stack'] );
				unset( $iterators[ $depth ] );
				if ( array() === $state['stack'] ) {
					$state['area_index']++;
				}
				continue;
			}

			$entry = $iterator->getFilename();
			$path  = $iterator->getPathname();
			$key   = $state['stack'][ $depth ]['logical_directory'] . '/' . $entry;
			$iterator->next();
			$state['stack'][ $depth ]['next_position']++;
			$scanned++;

			if ( is_link( $path ) ) {
				throw $this->failure( 'orphan_scan_failed', 'A symlink was found in the private artifact tree.' );
			}
			if ( is_dir( $path ) ) {
				if ( ! $this->is_cursor_directory_key( $key ) || count( $state['stack'] ) >= self::CURSOR_MAX_DEPTH ) {
					throw $this->failure( 'orphan_scan_failed', 'The private artifact tree has an invalid directory shape.' );
				}
				$mtime = @filemtime( $path );
				if ( false === $mtime ) {
					throw $this->failure( 'orphan_scan_failed', 'An artifact directory timestamp could not be read.' );
				}
				$state['stack'][] = array( 'logical_directory' => $key, 'next_position' => 0, 'directory_mtime' => $mtime );
				continue;
			}
			if ( ! is_file( $path ) ) {
				throw $this->failure( 'orphan_scan_failed', 'An unsupported object was found in the private artifact tree.' );
			}
			try {
				$this->assert_any_key( $key );
			} catch ( GHCA_ACD_Archive_Artifact_Store_Exception $error ) {
				throw $this->failure( 'orphan_scan_failed', 'The private artifact tree contains an invalid logical key.' );
			}
			$mtime = @filemtime( $path );
			if ( false === $mtime ) {
				throw $this->failure( 'orphan_scan_failed', 'An artifact candidate timestamp could not be read.' );
			}
			if ( $mtime >= $state['older_than_epoch'] ) {
				continue;
			}
			$candidate = $this->candidate_document( $key, $mtime );
			if ( null !== $state['after'] && $this->compare_tuple( $candidate, $state['after'] ) <= 0 ) {
				continue;
			}
			$this->retain_cursor_candidate( $state['selected'], $candidate );
		}

		// Do not return a cursor pointing one position past a directory's final
		// entry. Collapsing already-exhausted iterators does not inspect another
		// filesystem object and keeps a generated continuation resumable.
		while ( array() !== $state['stack'] ) {
			$depth = count( $state['stack'] ) - 1;
			if ( ! isset( $iterators[ $depth ] ) || $iterators[ $depth ]->valid() ) {
				break;
			}
			array_pop( $state['stack'] );
			unset( $iterators[ $depth ] );
			if ( array() === $state['stack'] ) {
				$state['area_index']++;
			}
		}
		if ( ! $this->ensure_cursor_frame( $state ) ) {
			return $this->complete_cursor_scan( $state, $scanned );
		}

		return array(
			'candidates' => array(),
			'scanned'    => $scanned,
			'truncated'  => true,
			'next_cursor' => $this->checked_cursor( $state ),
		);
	}

	/** @param array<string,string> $identity @return array<int,string> */
	private function identity( array $identity ): array {
		$expected = array( 'tenant_id', 'stream_id', 'archive_id', 'artifact_id' );
		$actual   = array_keys( $identity );
		sort( $actual, SORT_STRING );
		$sorted = $expected;
		sort( $sorted, SORT_STRING );
		if ( $actual !== $sorted ) {
			throw $this->failure( 'artifact_key_invalid', 'The artifact identity is invalid.' );
		}
		$ids = array();
		foreach ( $expected as $field ) {
			if ( ! is_string( $identity[ $field ] ) || 1 !== preg_match( '/^[a-f0-9]{32}$/', $identity[ $field ] ) ) {
				throw $this->failure( 'artifact_key_invalid', 'The artifact identity is invalid.' );
			}
			$ids[] = $identity[ $field ];
		}
		return $ids;
	}

	/** @return array<int,string> */
	private function assert_staging_key( string $key ): array {
		if ( 1 !== preg_match( '#^staging/([a-f0-9]{32})/([a-f0-9]{32})/([a-f0-9]{32})/([a-f0-9]{32})/([a-f0-9]{32})\.part$#D', $key, $match ) ) {
			throw $this->failure( 'artifact_key_invalid', 'The staging artifact key is invalid.' );
		}
		return array_slice( $match, 1 );
	}

	/** @return array<int,string> */
	private function assert_committed_key( string $key, string $kind ): array {
		$this->assert_kind( $kind );
		$extension = 'ledger' === $kind ? 'json' : 'pdf';
		if ( 1 !== preg_match( '#^committed/([a-f0-9]{32})/([a-f0-9]{32})/([a-f0-9]{32})/([a-f0-9]{32})\.' . $extension . '$#D', $key, $match ) ) {
			throw $this->failure( 'artifact_key_invalid', 'The committed artifact key is invalid.' );
		}
		return array_slice( $match, 1 );
	}

	private function assert_kind( string $kind ): void {
		if ( ! array_key_exists( $kind, self::MAX_BYTES ) ) {
			throw $this->failure( 'artifact_key_invalid', 'The artifact kind is invalid.' );
		}
	}

	private function assert_expected_bytes( string $kind, int $byte_count, string $sha256 ): void {
		$this->assert_kind( $kind );
		if ( $byte_count < 1 || $byte_count > self::MAX_BYTES[ $kind ] ) {
			throw $this->failure( 'artifact_size_exceeded', 'The expected artifact byte count is invalid.' );
		}
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $sha256 ) ) {
			throw $this->failure( 'artifact_digest_mismatch', 'The expected artifact digest is invalid.' );
		}
	}

	private function path_for_new_key( string $key ): string {
		$this->assert_any_key( $key );
		$relative_parent = dirname( str_replace( '/', DIRECTORY_SEPARATOR, $key ) );
		$this->ensure_directories( $relative_parent );
		return $this->contained_path( $key );
	}

	private function path_for_existing_key( string $key ): string {
		$this->assert_any_key( $key );
		$path = $this->contained_path( $key );
		$this->assert_components_safe( $key );
		return $path;
	}

	private function assert_any_key( string $key ): void {
		if ( false !== strpos( $key, '\\' ) || false !== strpos( $key, '..' ) || 1 === preg_match( '/[\x00-\x1f\x7f]/', $key )
			|| 1 === preg_match( '#^(?:/|[A-Za-z]:|//)#', $key ) ) {
			throw $this->failure( 'artifact_key_invalid', 'The artifact key is invalid.' );
		}
		if ( 0 === strpos( $key, 'staging/' ) ) {
			$this->assert_staging_key( $key );
			return;
		}
		if ( 0 === strpos( $key, 'committed/' ) ) {
			if ( 1 !== preg_match( '#^committed/(?:[a-f0-9]{32}/){3}[a-f0-9]{32}\.(?:pdf|json)$#D', $key ) ) {
				throw $this->failure( 'artifact_key_invalid', 'The artifact key is invalid.' );
			}
			return;
		}
		throw $this->failure( 'artifact_key_invalid', 'The artifact key is invalid.' );
	}

	private function contained_path( string $key ): string {
		$path = $this->normalize_path( $this->root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $key ) );
		if ( ! $this->within( $path, $this->root ) ) {
			throw $this->failure( 'artifact_path_escape', 'The artifact path escapes the private root.' );
		}
		return $path;
	}

	private function ensure_directories( string $relative ): void {
		$current = $this->root;
		foreach ( preg_split( '#[\\\\/]#', $relative ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				throw $this->failure( 'artifact_key_invalid', 'The artifact directory key is invalid.' );
			}
			$current .= DIRECTORY_SEPARATOR . $segment;
			if ( is_link( $current ) ) {
				throw $this->failure( 'artifact_symlink_rejected', 'A symlink component was rejected.' );
			}
			if ( file_exists( $current ) ) {
				if ( ! is_dir( $current ) ) {
					throw $this->failure( 'artifact_path_escape', 'An artifact directory component is not a directory.' );
				}
			} else {
				if ( ! @mkdir( $current, 0700 ) ) {
					throw $this->failure( 'artifact_directory_create_failed', 'An artifact directory could not be created.' );
				}
				$this->restrict_directory( $current );
			}
			$real = realpath( $current );
			if ( false === $real || ! $this->within( $this->normalize_path( $real ), $this->root ) ) {
				throw $this->failure( 'artifact_path_escape', 'An artifact directory escaped the private root.' );
			}
		}
	}

	private function assert_components_safe( string $key ): void {
		$parts   = explode( '/', $key );
		$current = $this->root;
		foreach ( $parts as $part ) {
			$current .= DIRECTORY_SEPARATOR . $part;
			if ( is_link( $current ) ) {
				throw $this->failure( 'artifact_symlink_rejected', 'A symlink component was rejected.' );
			}
		}
		$parent = realpath( dirname( $current ) );
		if ( false === $parent || ! $this->within( $this->normalize_path( $parent ), $this->root ) ) {
			throw $this->failure( 'artifact_path_escape', 'The artifact parent escapes the private root.' );
		}
	}

	/** @return resource */
	private function open_regular( string $path, string $mode, string $reason ) {
		$before = @lstat( $path );
		if ( false === $before || is_link( $path ) || ! is_file( $path ) ) {
			throw $this->failure( $reason, 'The artifact is not a safe regular file.' );
		}
		$handle = @fopen( $path, $mode );
		if ( false === $handle ) {
			throw $this->failure( $reason, 'The artifact could not be opened.' );
		}
		$after = @fstat( $handle );
		if ( false === $after || ( isset( $before['dev'], $before['ino'], $after['dev'], $after['ino'] )
			&& ( (string) $before['dev'] !== (string) $after['dev'] || (string) $before['ino'] !== (string) $after['ino'] ) ) ) {
			@fclose( $handle );
			throw $this->failure( 'artifact_symlink_rejected', 'The artifact changed while it was opened.' );
		}
		return $handle;
	}

	private function write_all( $handle, string $bytes ): void {
		$offset = 0;
		$length = strlen( $bytes );
		while ( $offset < $length ) {
			$written = @fwrite( $handle, substr( $bytes, $offset ) );
			if ( false === $written || 0 === $written ) {
				throw $this->failure( 'artifact_write_failed', 'The staging artifact could not be written.' );
			}
			$offset += $written;
		}
	}

	/** @return array<string,mixed> */
	private function verify_path( string $path, string $kind, int $byte_count, string $sha256 ): array {
		$handle = $this->open_regular( $path, 'rb', 'artifact_open_failed' );
		try {
			return $this->verify_handle( $handle, $kind, $byte_count, $sha256 );
		} finally {
			@fclose( $handle );
		}
	}

	/** @param resource $handle @return array<string,mixed> */
	private function verify_handle( $handle, string $kind, int $byte_count, string $sha256 ): array {
		$stat = @fstat( $handle );
		if ( false === $stat || (int) $stat['size'] !== $byte_count ) {
			throw $this->failure( 'artifact_size_mismatch', 'The artifact read-back byte count does not match.' );
		}
		if ( $byte_count < 1 || $byte_count > self::MAX_BYTES[ $kind ] ) {
			throw $this->failure( 'artifact_size_exceeded', 'The artifact exceeds its approved byte ceiling.' );
		}
		if ( 0 !== @fseek( $handle, 0, SEEK_SET ) ) {
			throw $this->failure( 'artifact_open_failed', 'The artifact could not be read from its start.' );
		}
		$hash  = hash_init( 'sha256' );
		$count = 0;
		while ( ! feof( $handle ) ) {
			$chunk = @fread( $handle, self::CHUNK_BYTES );
			if ( false === $chunk ) {
				throw $this->failure( 'artifact_open_failed', 'The artifact could not be read back.' );
			}
			if ( '' === $chunk ) {
				break;
			}
			$count += strlen( $chunk );
			hash_update( $hash, $chunk );
		}
		if ( $count !== $byte_count ) {
			throw $this->failure( 'artifact_size_mismatch', 'The artifact read-back byte count does not match.' );
		}
		$digest = hash_final( $hash );
		if ( ! hash_equals( $sha256, $digest ) ) {
			throw $this->failure( 'artifact_digest_mismatch', 'The artifact read-back digest does not match.' );
		}
		if ( 'ledger' === $kind ) {
			$this->validate_ledger( $handle, $byte_count );
		} else {
			$this->validate_pdf( $handle, $byte_count );
		}
		return array( 'byte_count' => $count, 'content_digest' => $digest );
	}

	/** @param resource $handle */
	private function validate_pdf( $handle, int $size ): void {
		if ( 0 !== @fseek( $handle, 0, SEEK_SET ) ) {
			throw $this->failure( 'artifact_media_invalid', 'The PDF header could not be read.' );
		}
		$header = @fread( $handle, 8 );
		if ( false === $header || 1 !== preg_match( '/^%PDF-(?:1\.[0-7]|2\.0)$/D', $header ) ) {
			throw $this->failure( 'artifact_media_invalid', 'The artifact is not an approved PDF structure.' );
		}
		$tail_size = min( self::PDF_TAIL_BYTES, $size );
		if ( 0 !== @fseek( $handle, $size - $tail_size, SEEK_SET ) ) {
			throw $this->failure( 'artifact_media_invalid', 'The PDF tail could not be read.' );
		}
		$tail = @fread( $handle, $tail_size );
		if ( false === $tail || 1 !== preg_match( '/startxref\s+([0-9]+)\s*%%EOF\s*$/D', $tail, $match ) ) {
			throw $this->failure( 'artifact_media_invalid', 'The PDF trailer is incomplete.' );
		}
		$offset_text = $match[1];
		if ( strlen( $offset_text ) > 18 ) {
			throw $this->failure( 'artifact_media_invalid', 'The PDF xref offset is invalid.' );
		}
		$offset = (int) $offset_text;
		if ( $offset < 8 || $offset >= $size || 0 !== @fseek( $handle, $offset, SEEK_SET ) ) {
			throw $this->failure( 'artifact_media_invalid', 'The PDF xref offset is invalid.' );
		}
		$opening = @fread( $handle, min( self::PDF_XREF_BYTES, $size - $offset ) );
		$classic = is_string( $opening ) && 1 === preg_match( '/^xref[ \t]*(?:\r\n|\r|\n)/', $opening );
		$stream  = is_string( $opening ) && 1 === preg_match( '/^[0-9]+\s+[0-9]+\s+obj\b[\s\S]*?\/Type\s*\/XRef\b/', $opening );
		if ( ! $classic && ! $stream ) {
			throw $this->failure( 'artifact_media_invalid', 'The PDF xref target is invalid.' );
		}
	}

	/** @param resource $handle */
	private function validate_ledger( $handle, int $size ): void {
		if ( 0 !== @fseek( $handle, 0, SEEK_SET ) ) {
			throw $this->failure( 'artifact_media_invalid', 'The ledger could not be read.' );
		}
		$bytes = @stream_get_contents( $handle, $size + 1 );
		if ( false === $bytes || strlen( $bytes ) !== $size || 1 !== preg_match( '//u', $bytes ) ) {
			throw $this->failure( 'artifact_media_invalid', 'The ledger is not valid UTF-8 canonical JSON.' );
		}
		try {
			$document = GHCA_ACD_Archive_Canonical_JSON::decode_canonical_bounded( $bytes, self::MAX_BYTES['ledger'] );
			$canonical = GHCA_ACD_Archive_Canonical_JSON::encode_bounded( $document, self::MAX_BYTES['ledger'] );
		} catch ( Throwable $error ) {
			throw $this->failure( 'artifact_media_invalid', 'The ledger is not valid canonical JSON.' );
		}
		if ( ! is_array( $document ) || array() === $document || $this->is_list( $document )
			|| ! isset( $document['schema_version'] ) || 1 !== $document['schema_version'] || $canonical !== $bytes ) {
			throw $this->failure( 'artifact_media_invalid', 'The ledger does not match the canonical v1 structure.' );
		}
	}

	/** @return array<string,mixed> */
	private function reuse_existing( string $staging_path, string $committed_path, string $key, string $kind, int $count, string $digest ): array {
		if ( is_link( $committed_path ) || ! is_file( $committed_path ) ) {
			throw $this->failure( 'artifact_commit_collision', 'The committed key is occupied by an unsafe object.' );
		}
		try {
			$this->verify_path( $committed_path, $kind, $count, $digest );
		} catch ( GHCA_ACD_Archive_Artifact_Store_Exception $error ) {
			if ( in_array( $error->reason_code(), array( 'artifact_open_failed', 'artifact_symlink_rejected', 'artifact_path_escape' ), true ) ) {
				throw $this->failure( 'artifact_commit_collision', 'The committed key is occupied by an unsafe object.' );
			}
			throw $this->failure( 'artifact_immutable_mismatch', 'The existing committed artifact does not match the expected immutable bytes.' );
		}
		$cleanup_pending = ! @unlink( $staging_path );
		return array(
			'committed_key'          => $key,
			'byte_count'             => $count,
			'content_digest'         => $digest,
			'reused'                 => true,
			'staging_cleanup_pending' => $cleanup_pending,
		);
	}

	/** @param array<string,mixed>|null $after @return array<string,mixed> */
	private function new_cursor_state( int $cutoff, ?array $after ): array {
		return array(
			'cursor_schema_version' => self::CURSOR_SCHEMA_VERSION,
			'root_digest'          => hash( 'sha256', $this->root ),
			'older_than_epoch'     => $cutoff,
			'after'                => $after,
			'area_index'           => 0,
			'stack'                => array(),
			'selected'             => array(),
		);
	}

	/** @param array<string,mixed> $state */
	private function ensure_cursor_frame( array &$state ): bool {
		$areas = array( 'staging', 'committed' );
		while ( array() === $state['stack'] && $state['area_index'] < count( $areas ) ) {
			$area = $areas[ $state['area_index'] ];
			$path = $this->contained_path( $area );
			if ( is_link( $path ) ) {
				throw $this->failure( 'orphan_scan_failed', 'A symlink was found in the private artifact tree.' );
			}
			if ( ! file_exists( $path ) ) {
				$state['area_index']++;
				continue;
			}
			if ( ! is_dir( $path ) ) {
				throw $this->failure( 'orphan_scan_failed', 'An artifact area is not a directory.' );
			}
			$real  = realpath( $path );
			$mtime = @filemtime( $path );
			if ( false === $real || ! $this->within( $this->normalize_path( $real ), $this->root ) || false === $mtime ) {
				throw $this->failure( 'orphan_scan_failed', 'An artifact area could not be validated.' );
			}
			$state['stack'][] = array(
				'logical_directory' => $area,
				'next_position'     => 0,
				'directory_mtime'   => $mtime,
			);
		}
		return array() !== $state['stack'];
	}

	/** @param array<string,mixed> $frame */
	private function revalidate_cursor_directory( array $frame ): string {
		if ( ! $this->is_cursor_directory_key( $frame['logical_directory'] ) ) {
			throw $this->failure( 'orphan_cursor_invalid', 'The orphan cursor directory is invalid.' );
		}
		$path  = $this->contained_path( $frame['logical_directory'] );
		$real  = realpath( $path );
		$mtime = @filemtime( $path );
		if ( is_link( $path ) || ! is_dir( $path ) || false === $real
			|| ! $this->within( $this->normalize_path( $real ), $this->root )
			|| false === $mtime || $mtime !== $frame['directory_mtime'] ) {
			throw $this->failure( 'orphan_cursor_stale', 'An active orphan traversal directory changed.' );
		}
		return $path;
	}

	private function is_cursor_directory_key( string $key ): bool {
		return 1 === preg_match( '#^(?:staging(?:/[a-f0-9]{32}){0,4}|committed(?:/[a-f0-9]{32}){0,3})$#D', $key );
	}

	/** @return array<string,mixed> */
	private function candidate_document( string $key, int $mtime ): array {
		$parts = explode( '/', $key );
		return array(
			'logical_key' => $key,
			'artifact_id' => 'committed' === $parts[0] ? pathinfo( $parts[4], PATHINFO_FILENAME ) : $parts[4],
			'area'        => $parts[0],
			'mtime'       => $mtime,
		);
	}

	/** @param array<string,mixed> $left @param array<string,mixed> $right */
	private function compare_tuple( array $left, array $right ): int {
		if ( $left['mtime'] !== $right['mtime'] ) {
			return $left['mtime'] < $right['mtime'] ? -1 : 1;
		}
		return strcmp( $left['logical_key'], $right['logical_key'] );
	}

	/** @param array<int,array<string,mixed>> $selected @param array<string,mixed> $candidate */
	private function retain_cursor_candidate( array &$selected, array $candidate ): void {
		foreach ( $selected as $existing ) {
			if ( $existing['logical_key'] === $candidate['logical_key'] ) {
				return;
			}
		}
		$selected[] = $candidate;
		usort( $selected, function ( array $left, array $right ): int {
			return $this->compare_tuple( $left, $right );
		} );
		if ( count( $selected ) > self::CURSOR_RETAIN_LIMIT ) {
			array_pop( $selected );
		}
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	private function complete_cursor_scan( array $state, int $scanned ): array {
		foreach ( $state['selected'] as $candidate ) {
			$this->revalidate_cursor_candidate( $candidate, $state['older_than_epoch'] );
		}
		$page     = array_slice( $state['selected'], 0, self::CURSOR_PAGE_SIZE );
		$has_more = count( $state['selected'] ) > self::CURSOR_PAGE_SIZE;
		$next     = null;
		if ( $has_more ) {
			$last = $page[ count( $page ) - 1 ];
			$next = $this->checked_cursor( $this->new_cursor_state(
				$state['older_than_epoch'],
				array( 'mtime' => $last['mtime'], 'logical_key' => $last['logical_key'] )
			) );
		}
		return array(
			'candidates' => $page,
			'scanned'    => $scanned,
			'truncated'  => $has_more,
			'next_cursor' => $next,
		);
	}

	/** @param array<string,mixed> $candidate */
	private function revalidate_cursor_candidate( array $candidate, int $cutoff ): void {
		try {
			$path = $this->path_for_existing_key( $candidate['logical_key'] );
		} catch ( Throwable $error ) {
			throw $this->failure( 'orphan_cursor_stale', 'A buffered orphan candidate changed before emission.' );
		}
		$mtime = @filemtime( $path );
		if ( is_link( $path ) || ! is_file( $path ) || false === $mtime
			|| $mtime !== $candidate['mtime'] || $mtime >= $cutoff ) {
			throw $this->failure( 'orphan_cursor_stale', 'A buffered orphan candidate changed before emission.' );
		}
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	private function checked_cursor( array $state ): array {
		try {
			$payload = GHCA_ACD_Archive_Canonical_JSON::encode_bounded( $state, self::CURSOR_MAX_BYTES );
			$state['cursor_hmac'] = hash_hmac( 'sha256', self::CURSOR_HMAC_DOMAIN . $payload, $this->cursor_hmac_key );
			GHCA_ACD_Archive_Canonical_JSON::encode_bounded( $state, self::CURSOR_MAX_BYTES );
		} catch ( Throwable $error ) {
			throw $this->failure( 'orphan_cursor_invalid', 'The orphan cursor could not be encoded safely.' );
		}
		return $state;
	}

	/** @param array<string,mixed> $cursor @return array<string,mixed> */
	private function load_cursor( array $cursor ): array {
		try {
			GHCA_ACD_Archive_Canonical_JSON::encode_bounded( $cursor, self::CURSOR_MAX_BYTES );
			if ( ! $this->has_exact_fields( $cursor, array( 'cursor_schema_version', 'root_digest', 'older_than_epoch', 'after', 'area_index', 'stack', 'selected', 'cursor_hmac' ) )
				|| ! is_string( $cursor['cursor_hmac'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $cursor['cursor_hmac'] ) ) {
				throw new UnexpectedValueException( 'invalid cursor authenticator' );
			}
			$provided_hmac = $cursor['cursor_hmac'];
			unset( $cursor['cursor_hmac'] );
			$payload       = GHCA_ACD_Archive_Canonical_JSON::encode_bounded( $cursor, self::CURSOR_MAX_BYTES );
			$expected_hmac = hash_hmac( 'sha256', self::CURSOR_HMAC_DOMAIN . $payload, $this->cursor_hmac_key );
			if ( ! hash_equals( $expected_hmac, $provided_hmac ) ) {
				throw new UnexpectedValueException( 'invalid cursor authenticator' );
			}

			if ( ! $this->has_exact_fields( $cursor, array( 'cursor_schema_version', 'root_digest', 'older_than_epoch', 'after', 'area_index', 'stack', 'selected' ) )
				|| self::CURSOR_SCHEMA_VERSION !== $cursor['cursor_schema_version']
				|| ! is_string( $cursor['root_digest'] ) || ! hash_equals( hash( 'sha256', $this->root ), $cursor['root_digest'] )
				|| ! is_int( $cursor['older_than_epoch'] ) || $cursor['older_than_epoch'] < 0
				|| ! is_int( $cursor['area_index'] ) || $cursor['area_index'] < 0 || $cursor['area_index'] > 2
				|| ! is_array( $cursor['stack'] ) || ! $this->is_list( $cursor['stack'] ) || count( $cursor['stack'] ) > self::CURSOR_MAX_DEPTH
				|| ! is_array( $cursor['selected'] ) || ! $this->is_list( $cursor['selected'] ) || count( $cursor['selected'] ) > self::CURSOR_RETAIN_LIMIT ) {
				throw new UnexpectedValueException( 'invalid cursor envelope' );
			}

			$after = $cursor['after'];
			if ( null !== $after ) {
				if ( ! is_array( $after ) || ! $this->has_exact_fields( $after, array( 'mtime', 'logical_key' ) )
					|| ! is_int( $after['mtime'] ) || $after['mtime'] < 0 || $after['mtime'] >= $cursor['older_than_epoch']
					|| ! is_string( $after['logical_key'] ) ) {
					throw new UnexpectedValueException( 'invalid cursor filter' );
				}
				$this->assert_any_key( $after['logical_key'] );
			}

			if ( 2 === $cursor['area_index'] && array() !== $cursor['stack'] ) {
				throw new UnexpectedValueException( 'contradictory cursor area' );
			}
			$previous_directory = null;
			foreach ( $cursor['stack'] as $index => $frame ) {
				if ( ! is_array( $frame ) || ! $this->has_exact_fields( $frame, array( 'logical_directory', 'next_position', 'directory_mtime' ) )
					|| ! is_string( $frame['logical_directory'] ) || ! $this->is_cursor_directory_key( $frame['logical_directory'] )
					|| ! is_int( $frame['next_position'] ) || $frame['next_position'] < 0 || $frame['next_position'] > self::CURSOR_MAX_POSITION
					|| ! is_int( $frame['directory_mtime'] ) || $frame['directory_mtime'] < 0 ) {
					throw new UnexpectedValueException( 'invalid cursor frame' );
				}
				$parts = explode( '/', $frame['logical_directory'] );
				if ( 0 === $index ) {
					$expected_area = 0 === $cursor['area_index'] ? 'staging' : 'committed';
					if ( count( $parts ) !== 1 || $frame['logical_directory'] !== $expected_area ) {
						throw new UnexpectedValueException( 'contradictory cursor root frame' );
					}
				} elseif ( $frame['logical_directory'] !== $previous_directory . '/' . $parts[ count( $parts ) - 1 ] ) {
					throw new UnexpectedValueException( 'contradictory cursor frame chain' );
				}
				$previous_directory = $frame['logical_directory'];
			}

			$previous = null;
			foreach ( $cursor['selected'] as $candidate ) {
				$this->validate_cursor_candidate_document( $candidate, $cursor['older_than_epoch'], $after );
				if ( null !== $previous && $this->compare_tuple( $previous, $candidate ) >= 0 ) {
					throw new UnexpectedValueException( 'unsorted cursor candidates' );
				}
				$previous = $candidate;
			}
			return $cursor;
		} catch ( Throwable $error ) {
			throw $this->failure( 'orphan_cursor_invalid', 'The orphan continuation cursor is malformed or contradictory.' );
		}
	}

	/** @param mixed $candidate @param array<string,mixed>|null $after */
	private function validate_cursor_candidate_document( $candidate, int $cutoff, ?array $after ): void {
		if ( ! is_array( $candidate ) || ! $this->has_exact_fields( $candidate, array( 'logical_key', 'artifact_id', 'area', 'mtime' ) )
			|| ! is_string( $candidate['logical_key'] ) || ! is_string( $candidate['artifact_id'] )
			|| 1 !== preg_match( '/^[a-f0-9]{32}$/D', $candidate['artifact_id'] )
			|| ! is_string( $candidate['area'] ) || ! in_array( $candidate['area'], array( 'staging', 'committed' ), true )
			|| ! is_int( $candidate['mtime'] ) || $candidate['mtime'] < 0 || $candidate['mtime'] >= $cutoff ) {
			throw new UnexpectedValueException( 'invalid cursor candidate' );
		}
		$this->assert_any_key( $candidate['logical_key'] );
		$expected = $this->candidate_document( $candidate['logical_key'], $candidate['mtime'] );
		if ( $expected['logical_key'] !== $candidate['logical_key'] || $expected['artifact_id'] !== $candidate['artifact_id']
			|| $expected['area'] !== $candidate['area'] || $expected['mtime'] !== $candidate['mtime']
			|| ( null !== $after && $this->compare_tuple( $candidate, $after ) <= 0 ) ) {
			throw new UnexpectedValueException( 'contradictory cursor candidate' );
		}
	}

	/** @param array<mixed> $value @param array<int,string> $fields */
	private function has_exact_fields( array $value, array $fields ): bool {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $fields, SORT_STRING );
		return $actual === $fields;
	}

	private function restrict_directory( string $path ): void {
		if ( ! @chmod( $path, 0700 ) && ! $this->windows ) {
			throw $this->failure( 'artifact_permissions_unsafe', 'Artifact directory permissions could not be restricted.' );
		}
		if ( ! $this->windows && ( fileperms( $path ) & 0077 ) !== 0 ) {
			throw $this->failure( 'artifact_permissions_unsafe', 'Artifact directory permissions are not restrictive.' );
		}
	}

	private function restrict_file( string $path ): void {
		if ( ! @chmod( $path, 0600 ) && ! $this->windows ) {
			throw $this->failure( 'artifact_permissions_unsafe', 'Artifact file permissions could not be restricted.' );
		}
		if ( ! $this->windows && ( fileperms( $path ) & 0077 ) !== 0 ) {
			throw $this->failure( 'artifact_permissions_unsafe', 'Artifact file permissions are not restrictive.' );
		}
	}

	private function is_absolute( string $path ): bool {
		return 1 === preg_match( '#^(?:[A-Za-z]:[\\\\/]|\\\\\\\\|/)#', $path );
	}

	private function normalize_path( string $path ): string {
		$path = str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $path );
		$path = rtrim( $path, DIRECTORY_SEPARATOR );
		return $this->windows ? strtolower( $path ) : $path;
	}

	private function within( string $path, string $root ): bool {
		$path = $this->windows ? strtolower( $path ) : $path;
		$root = $this->windows ? strtolower( $root ) : $root;
		return $path === $root || 0 === strpos( $path, $root . DIRECTORY_SEPARATOR );
	}

	/** @param array<mixed> $value */
	private function is_list( array $value ): bool {
		$index = 0;
		foreach ( $value as $key => $_ ) {
			if ( $key !== $index ) {
				return false;
			}
			$index++;
		}
		return true;
	}

	private function failure( string $reason, string $message ): GHCA_ACD_Archive_Artifact_Store_Exception {
		return new GHCA_ACD_Archive_Artifact_Store_Exception( $reason, $message );
	}
}
