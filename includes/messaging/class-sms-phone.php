<?php
/**
 * Employee mobile-number normalization and masking.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_SMS_Phone {
	public static function for_user( int $user_id ): string {
		$phone = trim( (string) get_user_meta( $user_id, 'billing_phone', true ) );
		if ( '' === $phone ) {
			$phone = trim( (string) get_user_meta( $user_id, 'phone', true ) );
		}
		if ( '' === $phone && function_exists( 'xprofile_get_field_data' ) ) {
			$phone = trim( (string) xprofile_get_field_data( 'Phone', $user_id ) );
		}
		return self::normalize( $phone );
	}

	public static function normalize( string $phone ): string {
		$phone = preg_replace( '/[^0-9+]/', '', trim( $phone ) );
		if ( ! is_string( $phone ) || ! preg_match( '/^\+[1-9][0-9]{7,14}$/', $phone ) ) {
			return '';
		}
		return $phone;
	}

	public static function mask( string $phone ): string {
		$phone = self::normalize( $phone );
		return '' === $phone ? '***' : '+' . str_repeat( '*', max( 4, strlen( $phone ) - 5 ) ) . substr( $phone, -4 );
	}

	public static function hash( string $phone ): string {
		$phone = self::normalize( $phone );
		return '' === $phone ? '' : hash_hmac( 'sha256', $phone, wp_salt( 'auth' ) );
	}
}
