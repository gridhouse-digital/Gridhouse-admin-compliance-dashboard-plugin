<?php

/** Production CSPRNG archive identifier generator. */
final class GHCA_ACD_Random_Archive_Id_Generator implements GHCA_ACD_Archive_Id_Generator {
	public function generate(): string {
		return bin2hex( random_bytes( 16 ) );
	}
}
