<?php

/** Production UTC clock with the archive's exact seconds-precision format. */
final class GHCA_ACD_System_Archive_Clock implements GHCA_ACD_Archive_Clock {
	public function now_gmt(): string {
		return gmdate( 'Y-m-d\TH:i:s\Z' );
	}
}
