<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Readable course titles for the redesigned admin dashboard (presentation only).
 *
 * LearnDash titles are often stored in capitals ("FATAL FIVE", "CPR/FIRST AID"). CSS text-transform cannot fix
 * that (it does not lower-case), and mb_convert_case() breaks acronyms ("Cpr") and possessives ("Employee'S").
 * This formatter converts a title to Title Case word by word:
 *
 * - known acronyms stay in capitals (CPR, ODP, HIPAA, … extendable with the ghca_acd_course_title_acronyms filter);
 * - words with digits are kept as written (course and module codes: "ODP101", "M-3", "2026");
 * - Roman numerals stay in capitals (Part II);
 * - small words (and, of, the, …) are lower-case except at the start, after a colon or after an opening bracket;
 * - hyphenated, slashed and bracketed parts are handled separately ("Person-Centered", "CPR/First Aid");
 * - in a title that already mixes cases, a word written with any capital is left exactly as it is (iPad, McKenzie),
 *   and only all-lower-case words are capitalized.
 *
 * Nothing is saved: course posts, ids and certificates are never changed.
 */
final class GHCA_ACD_Course_Title {

	/** @var array<string,bool>|null */
	private static $acronyms = null;

	const SMALL = array( 'a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'from', 'in', 'into', 'nor', 'of', 'on', 'or', 'per', 'the', 'to', 'vs', 'via', 'with' );

	/** @return array<string,bool> upper-case acronym => true */
	private static function acronyms(): array {
		if ( null === self::$acronyms ) {
			// Words that are also ordinary English ("it", "us") are deliberately not listed.
			$list = array( 'CPR', 'AED', 'BLS', 'ACLS', 'PALS', 'ODP', 'OLTL', 'HIPAA', 'OSHA', 'DSP', 'LPN', 'RN', 'CNA', 'HHA', 'PA', 'DHS', 'OAPSA', 'CPS', 'APS', 'ISP', 'IDD', 'ID', 'ASD', 'HCBS', 'PPE', 'MAR', 'PRN', 'EMR', 'EHR', 'FAQ', 'USA', 'SIS', 'QA', 'QM', 'HR', 'ADA', 'CMS', 'CEU', 'TB', 'HIV', 'COVID', 'ADL', 'IADL', 'SCO', 'PCSP', 'PCP', 'LD', 'BSP', 'FBA', 'PBS', 'SOP' );
			$list = function_exists( 'apply_filters' ) ? (array) apply_filters( 'ghca_acd_course_title_acronyms', $list ) : $list;
			self::$acronyms = array();
			foreach ( $list as $a ) {
				$a = strtoupper( trim( (string) $a ) );
				if ( '' !== $a ) {
					self::$acronyms[ $a ] = true;
				}
			}
		}
		return self::$acronyms;
	}

	private static function lower( string $s ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}

	private static function upper_first( string $s ): string {
		if ( '' === $s ) {
			return $s;
		}
		if ( function_exists( 'mb_substr' ) ) {
			return mb_strtoupper( mb_substr( $s, 0, 1, 'UTF-8' ), 'UTF-8' ) . mb_substr( $s, 1, null, 'UTF-8' );
		}
		return ucfirst( $s );
	}

	private static function has_lower( string $s ): bool {
		return (bool) preg_match( '/\p{Ll}/u', $s );
	}

	private static function has_upper( string $s ): bool {
		return (bool) preg_match( '/\p{Lu}/u', $s );
	}

	/**
	 * One word (no spaces, hyphens or slashes).
	 *
	 * @param bool $all_caps Whether the whole title was written without lower-case letters.
	 * @param bool $first    Whether the word starts the title or a clause.
	 */
	private static function word( string $w, bool $all_caps, bool $first, bool $last ): string {
		if ( '' === $w || ! preg_match( '/\p{L}/u', $w ) ) {
			return $w; // punctuation, numbers
		}
		// Keep leading and trailing punctuation (quotes, brackets, commas) around the letters.
		if ( ! preg_match( '/^([^\p{L}\p{N}]*)(.*?)([^\p{L}\p{N}]*)$/us', $w, $m ) ) {
			return $w;
		}
		list( , $pre, $core, $post ) = $m;
		if ( '' === $core ) {
			return $w;
		}
		$upper = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $core, 'UTF-8' ) : strtoupper( $core );
		$acr   = self::acronyms();
		if ( isset( $acr[ $upper ] ) ) {
			return $pre . $upper . $post; // acronym
		}
		// Possessive or plural of an acronym: CPR's, ISPs.
		if ( preg_match( "/^(.+?)('S|’S|S)$/u", $upper, $pm ) && isset( $acr[ $pm[1] ] ) ) {
			return $pre . $pm[1] . self::lower( $pm[2] ) . $post;
		}
		if ( preg_match( '/\p{N}/u', $core ) ) {
			return $w; // codes such as ODP101, M3, 2026 are kept as written
		}
		if ( $all_caps && preg_match( '/^(?=[IVXLC]+$)(C{0,3})(XC|XL|L?X{0,3})(IX|IV|V?I{0,3})$/', $upper ) && strlen( $upper ) <= 6 && 'I' !== $upper ) {
			return $pre . $upper . $post; // Roman numeral (Part II)
		}
		if ( ! $all_caps && self::has_upper( $core ) ) {
			return $w; // the author chose this capitalization
		}
		$lower = self::lower( $core );
		if ( ! $first && ! $last && in_array( $lower, self::SMALL, true ) ) {
			return $pre . $lower . $post;
		}
		// Capitalize the first letter; keep the rest lower (Employee's, not Employee'S).
		return $pre . self::upper_first( $lower ) . $post;
	}

	public static function format( string $title ): string {
		$title = trim( preg_replace( '/\s+/u', ' ', $title ) );
		if ( '' === $title ) {
			return $title;
		}
		$all_caps = self::has_upper( $title ) && ! self::has_lower( $title );
		$words    = explode( ' ', $title );
		$count    = count( $words );
		$out      = array();
		$clause   = true;
		foreach ( $words as $i => $word ) {
			// Hyphens and slashes join parts that are each formatted ("Person-Centered", "CPR/First Aid").
			// Only between letters or digits, so markup-like text ("</b>") is not split.
			$parts = preg_split( '/(?<=[\p{L}\p{N}])([-\/–—])(?=[\p{L}\p{N}])/u', $word, -1, PREG_SPLIT_DELIM_CAPTURE );
			$built = '';
			foreach ( $parts as $j => $part ) {
				if ( 1 === $j % 2 ) {
					$built .= $part;
					continue;
				}
				$first  = $clause || ( $j > 0 ) || (bool) preg_match( '/^[(\[“"‘\']/u', $part );
				$built .= self::word( $part, $all_caps, $first, $i === $count - 1 );
			}
			$out[]  = $built;
			$clause = (bool) preg_match( '/[:.!?]$/u', $word ) || '-' === $word || '–' === $word || '—' === $word;
		}
		return implode( ' ', $out );
	}
}
