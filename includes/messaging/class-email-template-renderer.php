<?php
/**
 * Safe, email-client-compatible presentation for reminder email snapshots.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_Email_Template_Renderer {
	const THEME_VERSION = '1';

	/**
	 * @param array<string,mixed> $delivery
	 * @return array{html:string,text:string}
	 */
	public static function render( array $delivery ): array {
		$user       = get_userdata( (int) ( $delivery['employee_user_id'] ?? 0 ) );
		$brand      = GHCA_Dashboard_Branding::get();
		$org_name   = wp_specialchars_decode( GHCA_Dashboard_Branding::get_org_name(), ENT_QUOTES );
		$org_name   = '' !== trim( $org_name ) ? trim( $org_name ) : __( 'Training and Compliance', 'ghca-acd' );
		$first_name = $user ? trim( (string) get_user_meta( (int) $user->ID, 'first_name', true ) ) : '';
		if ( '' === $first_name && $user ) {
			$display_parts = preg_split( '/\s+/', trim( (string) $user->display_name ) );
			$first_name    = is_array( $display_parts ) && ! empty( $display_parts[0] ) ? (string) $display_parts[0] : '';
		}
		$first_name = '' !== $first_name ? $first_name : __( 'Employee', 'ghca-acd' );
		/*
		 * NOTE: do not greet the recipient here. The message body owns the
		 * greeting because it is shared with SMS, which has no wrapper. A
		 * greeting in this template renders directly above the message box and
		 * reads as a duplicate. $first_name is still used for the preheader.
		 */

		$urgency      = GHCA_ACD_Message_Template_Renderer::normalize_urgency( (string) ( $delivery['urgency'] ?? 'normal' ) );
		$message      = self::display_message( trim( (string) ( $delivery['message'] ?? '' ) ), $urgency );
		$urgency_data = self::urgency_data( $urgency, (string) $brand['primary'] );
		$portal_url   = esc_url_raw( GHCA_ACD_Data_Provider::get_page_url( 'my-courses', '/my-courses/' ) );
		$button_label = GHCA_ACD_Settings::get_reminder_button_label();
		$footer_text  = GHCA_ACD_Settings::get_reminder_footer_text();
		$support      = GHCA_Dashboard_Branding::get_support_email();
		$logo_url     = self::https_logo_url( GHCA_Dashboard_Branding::get_logo_url() );
		$primary      = sanitize_hex_color( (string) $brand['primary'] );
		$primary      = $primary ? $primary : '#176cad';
		$preheader    = sprintf( __( '%1$s training and compliance reminder for %2$s.', 'ghca-acd' ), $urgency_data['label'], $first_name );

		$logo_html = '';
		if ( '' !== $logo_url ) {
			$logo_html = sprintf(
				'<img src="%1$s" width="180" alt="%2$s" style="display:block;max-width:180px;max-height:64px;width:auto;height:auto;border:0;outline:none;text-decoration:none;">',
				esc_url( $logo_url ),
				esc_attr( $org_name )
			);
		}

		$cta_html = '';
		if ( '' !== $portal_url ) {
			$cta_html = sprintf(
				'<tr><td style="padding:0 36px 32px;"><table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr><td bgcolor="%1$s" style="border-radius:8px;background:%1$s;"><a href="%2$s" style="display:inline-block;padding:13px 22px;color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;line-height:20px;text-decoration:none;border-radius:8px;">%3$s</a></td></tr></table></td></tr>',
				esc_attr( $primary ),
				esc_url( $portal_url ),
				esc_html( $button_label )
			);
		}

		$support_html = '';
		if ( is_email( $support ) ) {
			$support_html = sprintf(
				'<p style="margin:8px 0 0;color:#64748b;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:18px;">%1$s <a href="mailto:%2$s" style="color:%3$s;text-decoration:underline;">%2$s</a></p>',
				esc_html__( 'Questions? Reply to this email or contact', 'ghca-acd' ),
				esc_attr( $support ),
				esc_attr( $primary )
			);
		}

		$html = '<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $org_name ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:#f4f7fb;word-spacing:normal;">'
			. '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . esc_html( $preheader ) . '</div>'
			. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:#f4f7fb;"><tr><td align="center" style="padding:28px 12px;">'
			. '<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #e5eaf1;border-radius:14px;overflow:hidden;box-shadow:0 8px 28px rgba(15,23,42,.08);">'
			. '<tr><td style="height:6px;background:' . esc_attr( $primary ) . ';font-size:0;line-height:0;">&nbsp;</td></tr>'
			. '<tr><td style="padding:28px 36px 20px;">' . $logo_html . '<p style="margin:' . ( '' !== $logo_html ? '14px' : '0' ) . ' 0 0;color:#172033;font-family:Arial,Helvetica,sans-serif;font-size:19px;font-weight:700;line-height:25px;">' . esc_html( $org_name ) . '</p></td></tr>'
			. '<tr><td style="padding:0 36px 18px;"><span style="display:inline-block;padding:6px 10px;border-radius:999px;background:' . esc_attr( $urgency_data['background'] ) . ';color:' . esc_attr( $urgency_data['color'] ) . ';font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;">' . esc_html( $urgency_data['label'] ) . '</span></td></tr>'
			. '<tr><td style="padding:0 36px 26px;"><div style="padding:18px 20px;border:1px solid #e5eaf1;border-radius:10px;background:#f8fafc;color:#334155;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;overflow-wrap:anywhere;">' . nl2br( esc_html( $message ) ) . '</div></td></tr>'
			. $cta_html
			. '<tr><td style="padding:22px 36px 26px;border-top:1px solid #e5eaf1;background:#f8fafc;"><p style="margin:0;color:#64748b;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:18px;">' . esc_html( $footer_text ) . '</p>' . $support_html . '<p style="margin:8px 0 0;color:#94a3b8;font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:17px;">' . esc_html( $org_name ) . '</p></td></tr>'
			. '</table></td></tr></table></body></html>';

		$text_lines = array(
			$org_name,
			$urgency_data['label'],
			'',
			$message,
		);
		if ( '' !== $portal_url ) {
			$text_lines[] = '';
			$text_lines[] = $button_label . ': ' . $portal_url;
		}
		$text_lines[] = '';
		$text_lines[] = $footer_text;
		if ( is_email( $support ) ) {
			$text_lines[] = sprintf( __( 'Questions: %s', 'ghca-acd' ), $support );
		}

		return array(
			'html' => $html,
			'text' => implode( "\n", $text_lines ),
		);
	}

	/** @return array{label:string,color:string,background:string} */
	private static function urgency_data( string $urgency, string $primary ): array {
		if ( 'urgent' === $urgency ) {
			return array( 'label' => __( 'Urgent - Training Reminder', 'ghca-acd' ), 'color' => '#b42318', 'background' => '#fef3f2' );
		}
		if ( 'important' === $urgency ) {
			return array( 'label' => __( 'Important - Training Reminder', 'ghca-acd' ), 'color' => '#b54708', 'background' => '#fffaeb' );
		}

		$color = sanitize_hex_color( $primary );
		return array( 'label' => __( 'Training Reminder', 'ghca-acd' ), 'color' => $color ? $color : '#176cad', 'background' => '#eff6ff' );
	}

	private static function https_logo_url( string $url ): string {
		$url = esc_url_raw( trim( $url ), array( 'https' ) );
		if ( 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) || '' === (string) wp_parse_url( $url, PHP_URL_HOST ) ) {
			return '';
		}
		if ( null !== wp_parse_url( $url, PHP_URL_USER ) || null !== wp_parse_url( $url, PHP_URL_PASS ) ) {
			return '';
		}

		return $url;
	}

	private static function display_message( string $message, string $urgency ): string {
		if ( 'normal' === $urgency ) {
			return $message;
		}

		$prefix  = strtoupper( $urgency );
		$display = preg_replace( '/^' . preg_quote( $prefix, '/' ) . ':\s*/i', '', ltrim( $message ) );
		return is_string( $display ) ? $display : $message;
	}
}
