<?php

interface GHCA_ACD_Archive_Artifact_Store {
	/** @param array<string,string> $identity */
	public function create_staging( array $identity ): string;

	/** @param array<string,string> $identity */
	public function committed_key( array $identity, string $kind ): string;

	/** @param resource $source @return array<string,mixed> */
	public function write_staging( string $staging_key, $source, string $kind ): array;

	/** @return array<string,mixed> */
	public function commit( string $staging_key, string $committed_key, string $kind, int $byte_count, string $sha256 ): array;

	/** @return resource */
	public function open_committed( string $committed_key, string $kind, int $byte_count, string $sha256 );

	/** @return array<string,mixed> */
	public function enumerate_candidates( ?int $older_than_epoch, int $limit = 1000, ?array $cursor = null ): array;
}
