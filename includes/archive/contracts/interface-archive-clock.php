<?php

interface GHCA_ACD_Archive_Clock {
	/** Return UTC with seconds precision as YYYY-MM-DDTHH:MM:SSZ. */
	public function now_gmt(): string;
}

