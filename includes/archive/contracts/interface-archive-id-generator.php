<?php

interface GHCA_ACD_Archive_Id_Generator {
	/** Return exactly 32 lowercase hexadecimal characters. */
	public function generate(): string;
}

