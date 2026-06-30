<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

final class GHCA_Dashboard_Branding {
  const OPTION = 'ghca_dashboard_brand';

  /** @var array<string,string>|null */
  private static $resolved = null;

  /** @return array<string,string> */
  public static function defaults(): array {
    return array(
      'primary'       => '#176cad',
      'secondary'     => '#59cd90',
      'accent'        => '',
      'org_name'      => '',
      'logo_url'      => '',
      'support_email' => 'info@lafiahealthcare.org',
    );
  }

  /** @return array<string,string> */
  public static function get(): array {
    if ( null !== self::$resolved ) {
      return self::$resolved;
    }

    $saved   = get_option( self::OPTION, array() );
    $saved   = is_array( $saved ) ? $saved : array();
    $brand   = array_merge( self::defaults(), $saved );
    $primary = self::sanitize_hex( (string) $brand['primary'], self::defaults()['primary'] );
    $accent  = self::sanitize_hex( (string) $brand['accent'], '' );

    self::$resolved = array(
      'primary'       => $primary,
      'secondary'     => self::sanitize_hex( (string) $brand['secondary'], self::defaults()['secondary'] ),
      'accent'        => $accent !== '' ? $accent : $primary,
      'org_name'      => sanitize_text_field( (string) $brand['org_name'] ),
      'logo_url'      => esc_url_raw( (string) $brand['logo_url'] ),
      'support_email' => sanitize_email( (string) $brand['support_email'] ) ?: self::defaults()['support_email'],
      'primary_dark'  => self::darken( $primary, 0.28 ),
      'primary_soft'  => self::mix_hex( $primary, '#ffffff', 0.9 ),
      'ring'          => self::rgba( $primary, 0.16 ),
    );

    return self::$resolved;
  }

  public static function get_org_name(): string {
    $brand = self::get();
    if ( ! empty( $brand['org_name'] ) ) {
      return $brand['org_name'];
    }

    return (string) get_bloginfo( 'name' );
  }

  public static function has_header_brand(): bool {
    $brand = self::get();
    return ! empty( $brand['logo_url'] ) || ! empty( $brand['org_name'] );
  }

  public static function get_header_org_name(): string {
    $brand = self::get();
    if ( ! empty( $brand['org_name'] ) ) {
      return $brand['org_name'];
    }

    return (string) get_bloginfo( 'name' );
  }

  public static function get_logo_url(): string {
    return self::get()['logo_url'];
  }

  public static function get_support_email(): string {
    return self::get()['support_email'];
  }

  public static function get_inline_css(): string {
    $brand = self::get();

    return sprintf(
      '.ghca-ecd,.ghca-acd,.ghca-acd__cert-modal,.ghca-acd__drawer,.ghca-acd__edit-modal{--ghca-primary:%1$s;--ghca-primary-dark:%2$s;--ghca-primary-soft:%3$s;--ghca-secondary:%4$s;--ghca-accent:%5$s;--ghca-ring:0 0 0 4px %6$s;}',
      esc_attr( $brand['primary'] ),
      esc_attr( $brand['primary_dark'] ),
      esc_attr( $brand['primary_soft'] ),
      esc_attr( $brand['secondary'] ),
      esc_attr( $brand['accent'] ),
      esc_attr( $brand['ring'] )
    );
  }

  /** @param mixed $value @return array<string,string> */
  public static function sanitize( $value ): array {
    if ( ! is_array( $value ) ) {
      return self::defaults();
    }

    $defaults = self::defaults();
    $primary  = self::sanitize_hex( (string) ( $value['primary'] ?? '' ), $defaults['primary'] );
    $accent   = self::sanitize_hex( (string) ( $value['accent'] ?? '' ), '' );
    $email    = sanitize_email( (string) ( $value['support_email'] ?? '' ) );

    return array(
      'primary'       => $primary,
      'secondary'     => self::sanitize_hex( (string) ( $value['secondary'] ?? '' ), $defaults['secondary'] ),
      'accent'        => $accent,
      'org_name'      => sanitize_text_field( (string) ( $value['org_name'] ?? '' ) ),
      'logo_url'      => esc_url_raw( (string) ( $value['logo_url'] ?? '' ) ),
      'support_email' => $email !== '' ? $email : $defaults['support_email'],
    );
  }

  private static function sanitize_hex( string $hex, string $fallback ): string {
    $hex = strtolower( ltrim( trim( $hex ), '#' ) );
    if ( preg_match( '/^[0-9a-f]{6}$/', $hex ) ) {
      return '#' . $hex;
    }

    $fallback = strtolower( ltrim( trim( $fallback ), '#' ) );
    if ( $fallback !== '' && preg_match( '/^[0-9a-f]{6}$/', $fallback ) ) {
      return '#' . $fallback;
    }

    return '';
  }

  /** @return array{0:int,1:int,2:int} */
  private static function hex_to_rgb( string $hex ): array {
    $hex = ltrim( $hex, '#' );

    return array(
      (int) hexdec( substr( $hex, 0, 2 ) ),
      (int) hexdec( substr( $hex, 2, 2 ) ),
      (int) hexdec( substr( $hex, 4, 2 ) ),
    );
  }

  private static function mix_hex( string $hex1, string $hex2, float $weight2 ): string {
    $rgb1 = self::hex_to_rgb( $hex1 );
    $rgb2 = self::hex_to_rgb( $hex2 );
    $w1   = 1 - $weight2;

    return sprintf(
      '#%02x%02x%02x',
      (int) round( $rgb1[0] * $w1 + $rgb2[0] * $weight2 ),
      (int) round( $rgb1[1] * $w1 + $rgb2[1] * $weight2 ),
      (int) round( $rgb1[2] * $w1 + $rgb2[2] * $weight2 )
    );
  }

  private static function darken( string $hex, float $amount ): string {
    return self::mix_hex( $hex, '#000000', max( 0, min( 1, $amount ) ) );
  }

  private static function rgba( string $hex, float $alpha ): string {
    $rgb   = self::hex_to_rgb( $hex );
    $alpha = max( 0, min( 1, $alpha ) );

    return sprintf( 'rgba(%d,%d,%d,%.2f)', $rgb[0], $rgb[1], $rgb[2], $alpha );
  }
}
