<?php

/**
 * Explicit empty canonical JSON object.
 *
 * A PHP array cannot distinguish `{}` from `[]`: an empty array is
 * indistinguishable from an empty list and would silently encode as `[]`.
 * TD-07 defines objects and lists as distinct JSON types, so an object-valued
 * field that happens to be empty must still encode as `{}`. This immutable
 * singleton is the smallest explicit marker that removes that ambiguity; it is
 * an encoder representation only and is never a stored PHP-serialized object.
 */
final class GHCA_ACD_Archive_Empty_Object {
	/** @var self|null */
	private static $instance = null;

	private function __construct() {}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
}
