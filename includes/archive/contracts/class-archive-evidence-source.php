<?php

/** Read-only boundary for one normalized, consistent evidence document. */
interface GHCA_ACD_Archive_Evidence_Source {
	/**
	 * @param array<string,mixed> $capture_identity
	 * @param array<string,int> $limits
	 * @return array<string,mixed>
	 */
	public function read_consistent_evidence( array $capture_identity, array $limits ): array;
}
