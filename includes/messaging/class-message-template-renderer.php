<?php
/**
 * Plain-text reminder template validation and rendering.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_Message_Template_Renderer {
	const MAX_SUBJECT_LENGTH = 200;
	const MAX_MESSAGE_LENGTH = 4000;

	/** @var array<int,string> */
	private const ALLOWED_PLACEHOLDERS = array(
		'employee_first_name',
		'agency_name',
		'due_date',
		'portal_url',
		'compliance_contact_number',
	);

	/** @param array<string,string> $values */
	public static function render( string $template, array $values ) {
		$unknown = self::unknown_placeholders( $template );
		if ( ! empty( $unknown ) ) {
			return new WP_Error(
				'ghca_message_unknown_placeholder',
				sprintf(
					/* translators: %s: comma-separated placeholder names. */
					__( 'Unknown message placeholder(s): %s', 'ghca-acd' ),
					implode( ', ', $unknown )
				)
			);
		}

		$replace = array();
		foreach ( self::ALLOWED_PLACEHOLDERS as $name ) {
			$replace[ '[' . $name . ']' ] = isset( $values[ $name ] ) ? (string) $values[ $name ] : '';
		}

		return strtr( $template, $replace );
	}

	/** @return array<int,string> */
	public static function unknown_placeholders( string $template ): array {
		if ( ! preg_match_all( '/\[([a-z0-9_]+)\]/i', $template, $matches ) ) {
			return array();
		}

		$unknown = array_diff( array_map( 'strtolower', $matches[1] ), self::ALLOWED_PLACEHOLDERS );
		return array_values( array_unique( $unknown ) );
	}

	public static function normalize_urgency( string $urgency ): string {
		return in_array( $urgency, array( 'normal', 'important', 'urgent' ), true ) ? $urgency : 'normal';
	}

	public static function apply_urgency_prefix( string $value, string $urgency ): string {
		$urgency = self::normalize_urgency( $urgency );
		if ( 'normal' === $urgency ) {
			return $value;
		}

		$prefix = strtoupper( $urgency ) . ':';
		return 0 === stripos( ltrim( $value ), $prefix ) ? $value : $prefix . ' ' . $value;
	}

	public static function validate_lengths( string $subject, string $message, bool $subject_required = true ) {
		if ( $subject_required && '' === trim( $subject ) ) {
			return new WP_Error( 'ghca_message_subject_required', __( 'An email subject is required.', 'ghca-acd' ) );
		}
		if ( self::text_length( $subject ) > self::MAX_SUBJECT_LENGTH ) {
			return new WP_Error( 'ghca_message_subject_too_long', __( 'The email subject is too long.', 'ghca-acd' ) );
		}
		if ( '' === trim( $message ) ) {
			return new WP_Error( 'ghca_message_body_required', __( 'A reminder message is required.', 'ghca-acd' ) );
		}
		if ( self::text_length( $message ) > self::MAX_MESSAGE_LENGTH ) {
			return new WP_Error( 'ghca_message_body_too_long', __( 'The reminder message is too long.', 'ghca-acd' ) );
		}

		return true;
	}

	private static function text_length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}
}
