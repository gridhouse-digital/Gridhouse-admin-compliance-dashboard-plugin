<?php
/**
 * Encrypts provider secrets with a host key that is never stored in WordPress.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_Messaging_Secret_Store {
	const KEY_CONSTANT = 'GHCA_ACD_TWILIO_ENCRYPTION_KEY';

	public static function available(): bool {
		if ( ! defined( self::KEY_CONSTANT ) ) {
			return false;
		}
		$key = constant( self::KEY_CONSTANT );
		return is_string( $key ) && strlen( $key ) >= 32 && function_exists( 'openssl_encrypt' );
	}

	/** @return string|WP_Error */
	public static function encrypt( string $plaintext ) {
		if ( ! self::available() ) {
			return new WP_Error( 'ghca_twilio_secret_key_unavailable', __( 'Define GHCA_ACD_TWILIO_ENCRYPTION_KEY as a host secret of at least 32 characters before saving Twilio secrets.', 'ghca-acd' ) );
		}
		if ( '' === $plaintext || strlen( $plaintext ) > 256 ) {
			return new WP_Error( 'ghca_twilio_secret_invalid', __( 'The Twilio secret is empty or too long.', 'ghca-acd' ) );
		}

		$iv         = random_bytes( 12 );
		$tag        = '';
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, 'ghca-acd-twilio-v1' );
		if ( false === $ciphertext || 16 !== strlen( $tag ) ) {
			return new WP_Error( 'ghca_twilio_secret_encrypt_failed', __( 'The Twilio secret could not be encrypted.', 'ghca-acd' ) );
		}

		return 'v1:' . base64_encode( $iv . $tag . $ciphertext );
	}

	/** @return string|WP_Error */
	public static function decrypt( string $stored ) {
		if ( ! self::available() || 0 !== strpos( $stored, 'v1:' ) ) {
			return new WP_Error( 'ghca_twilio_secret_unavailable', __( 'The Twilio secret cannot be decrypted with the current host key.', 'ghca-acd' ) );
		}

		$raw = base64_decode( substr( $stored, 3 ), true );
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return new WP_Error( 'ghca_twilio_secret_corrupt', __( 'The stored Twilio secret is invalid.', 'ghca-acd' ) );
		}

		$iv         = substr( $raw, 0, 12 );
		$tag        = substr( $raw, 12, 16 );
		$ciphertext = substr( $raw, 28 );
		$plaintext  = openssl_decrypt( $ciphertext, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, 'ghca-acd-twilio-v1' );
		return false === $plaintext
			? new WP_Error( 'ghca_twilio_secret_decrypt_failed', __( 'The stored Twilio secret failed authentication.', 'ghca-acd' ) )
			: $plaintext;
	}

	/** @param mixed $value */
	public static function sanitize_api_secret( $value ): string {
		return self::sanitize_secret_option( (string) $value, GHCA_ACD_Settings::OPTION_TWILIO_API_SECRET );
	}

	/** @param mixed $value */
	public static function sanitize_webhook_token( $value ): string {
		return self::sanitize_secret_option( (string) $value, GHCA_ACD_Settings::OPTION_TWILIO_WEBHOOK_AUTH_TOKEN );
	}

	private static function sanitize_secret_option( string $value, string $option ): string {
		$current = (string) get_option( $option, '' );
		$value   = trim( $value );
		if ( '' === $value ) {
			return $current;
		}
		if ( 0 === strpos( $value, 'v1:' ) ) {
			$decrypted = self::decrypt( $value );
			if ( ! is_wp_error( $decrypted ) ) {
				return $value;
			}
			add_settings_error( 'ghca_acd_messaging', $decrypted->get_error_code(), $decrypted->get_error_message(), 'error' );
			return $current;
		}
		$encrypted = self::encrypt( $value );
		if ( is_wp_error( $encrypted ) ) {
			add_settings_error( 'ghca_acd_messaging', $encrypted->get_error_code(), $encrypted->get_error_message(), 'error' );
			return $current;
		}
		return $encrypted;
	}

	private static function key(): string {
		return hash( 'sha256', (string) constant( self::KEY_CONSTANT ), true );
	}
}
