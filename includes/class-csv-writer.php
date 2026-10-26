<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single CSV row writer for every export.
 *
 * fputcsv()'s default escape character is a backslash, so a backslash directly before a
 * quote leaves that quote undoubled and a strict RFC 4180 reader merges or shifts fields.
 * Rows are written with an empty escape character (plain RFC 4180 quoting) after each
 * original field has been neutralised against spreadsheet formula execution.
 */
final class GHCA_ACD_CSV {

	/**
	 * Prefix a leading formula character so spreadsheets treat the cell as text.
	 * Only the first character decides: line breaks inside a value are left alone.
	 */
	public static function neutralize( $value ): string {
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r", "\n" ), true ) ) {
			$value = "'" . $value;
		}
		return $value;
	}

	/** @param resource $out @param array<int|string,mixed> $row */
	public static function write_row( $out, array $row ): void {
		fputcsv( $out, array_map( array( __CLASS__, 'neutralize' ), array_values( $row ) ), ',', '"', '' );
	}
}
