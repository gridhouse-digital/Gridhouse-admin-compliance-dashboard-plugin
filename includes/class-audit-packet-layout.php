<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Print layout for the ODP Annual and Orientation audit packets (design board "Print: Orientation audit packet").
 *
 * Page 1: agency header (logo or monogram, packet name, who generated it and when, page X of N), the employee, a
 * four-figure summary box, the requirement areas with a status that prints as a symbol plus its word, key dates
 * and hours. Following pages: a running header, course records, gaps found, the attached certificates (numbered
 * A-1, A-2 … in the order they are merged) and a reviewer signature block.
 *
 * Everything shown is read from the calculator's audit data and the employee's own records; nothing is estimated
 * and no status is upgraded. The OLTL packet keeps its own layout (GHCA_Audit_PDF::render_oltl_cover_body).
 */
final class GHCA_Audit_Packet_Layout {
	const MARGIN     = 14;  // mm, left/right
	const TOP        = 24;  // mm, top margin of every summary page (room for the running header)
	const HEAD_Y     = 13;  // mm, where the page-1 header and the running headers are drawn
	const ORIENTATION_DAYS = 30;

	/** Area key => label, in print order. */
	const ANNUAL_AREAS = array(
		'person_centered'     => 'Person-Centered Practices',
		'abuse_prevention'    => 'Prevention of Abuse',
		'individual_rights'   => 'Individual Rights',
		'reporting_incidents' => 'Reporting Incidents',
		'behavior_supports'   => 'Behavior Supports',
		'individual_plan'     => 'Implementation of Individual Plan (ISP)',
	);
	const ORIENTATION_AREAS = array(
		'person_centered'     => 'Person-Centered Practices',
		'abuse_prevention'    => 'Prevention of Abuse',
		'individual_rights'   => 'Individual Rights',
		'reporting_incidents' => 'Reporting Incidents',
		'job_related'         => 'Job-related Knowledge',
	);

	/** Status kind => [symbol, text colour, background]. Every status also prints its word. */
	const KINDS = array(
		'completed'  => array( "\u{2714}", '#1d6b3a', '#e6f4ea' ),
		'progress'   => array( "\u{25D0}", '#1f4e8c', '#e8f0fb' ),
		'duesoon'    => array( "\u{25F7}", '#7a4f00', '#fff4dc' ),
		'overdue'    => array( "\u{26A0}", '#ffffff', '#b42318' ),
		'missing'    => array( "\u{2716}", '#a12020', '#fdecec' ),
		'review'     => array( "\u{25C9}", '#7a4f00', '#ffffff' ),
		'na'         => array( "\u{2298}", '#4a4a4a', '#efefef' ),
		'notstarted' => array( "\u{25CB}", '#4a4a4a', '#ffffff' ),
	);
	/** ASCII stand-ins when the TCPDF DejaVu font is not installed. */
	const ASCII = array( 'completed' => '+', 'progress' => '~', 'duesoon' => '!', 'overdue' => '!!', 'missing' => 'x', 'review' => '?', 'na' => '-', 'notstarted' => 'o' );

	/**
	 * Renders the summary pages. The caller has added page 1. $context: user_id, group, attachments (from
	 * attachment_labels()), employee name override. Leaves the document on its last summary page, with the
	 * plugin's normal 15 mm margins restored for whatever is appended next.
	 */
	public static function render( $pdf, array $data, string $tracker, array $context = array() ): void {
		$annual    = 'annual' === $tracker;
		$reporting = ! empty( $data['audit_period'] );
		$name      = trim( (string) ( $data['first_name'] ?? '' ) . ' ' . (string) ( $data['last_name'] ?? '' ) );
		$org       = wp_specialchars_decode( (string) GHCA_Dashboard_Branding::get_org_name(), ENT_QUOTES );
		$first     = (int) $pdf->getPage();

		$pdf->SetMargins( self::MARGIN, self::TOP, self::MARGIN );
		$pdf->SetAutoPageBreak( true, 16 );
		$subtitle = $reporting ? 'ODP training evidence packet — reporting period' : ( $annual ? 'ODP annual audit packet' : 'ODP orientation audit packet' );
		self::draw_first_header( $pdf, $org, $subtitle );

		$pdf->SetFont( 'helvetica', '', 10 );
		$html  = self::employee_block( $data, $name, $context );
		$html .= $annual ? self::annual_summary( $data, $reporting ) : self::orientation_summary( $data, $context );
		if ( ! empty( $data['evidence_issues'] ) ) {
			$html .= '<p><strong>Evidence review required:</strong> Records with missing or invalid completion dates are excluded from credited hours and included certificates.</p><ul>';
			foreach ( (array) $data['evidence_issues'] as $issue ) {
				$html .= '<li>' . esc_html( (string) $issue ) . '</li>';
			}
			$html .= '</ul>';
		}
		$html .= self::areas_table( $data, $annual, $reporting, (array) ( $context['attachments'] ?? array() ) );
		$html .= $annual ? self::annual_key_figures( $data, $reporting ) : self::orientation_key_dates( $data, $context );
		$html .= '<p style="font-size:7.5pt;color:#333333;">Every status prints as a symbol plus its word (Completed, In progress, Due soon, Overdue, Missing, Needs review, Not applicable). Colour is not needed to read this document.</p>';
		$pdf->writeHTML( $html, true, false, true, false, '' );

		$pdf->AddPage();
		$pdf->SetFont( 'helvetica', '', 10 );
		$html  = self::course_records( $data, $annual, (array) ( $context['attachments'] ?? array() ) );
		$html .= self::gaps( $data, $annual, $reporting );
		$html .= self::attachments_list( $data, (array) ( $context['attachments'] ?? array() ) );
		if ( GHCA_ACD_Settings::include_packet_course_details() && ! empty( $data['raw_completed_courses'] ) ) {
			$html .= self::course_details( $data );
		}
		$html .= self::signature_block();
		$pdf->writeHTML( $html, true, false, true, false, '' );

		self::stamp_headers( $pdf, $first, $org, $name, $context );
		$pdf->SetMargins( 15, 15, 15 );
		$pdf->SetAutoPageBreak( true, 15 );
	}

	/**
	 * Attachment labels in the exact order GHCA_Audit_PDF::ajax_merge appends certificates: each completed course's
	 * certificates in course order, then any remaining ones by index.
	 *
	 * @return array<int,array{label:string,course_key:string}> certificate index => label and course key
	 */
	public static function attachment_labels( array $courses, array $course_keys, int $total ): array {
		$by_key = array();
		foreach ( $course_keys as $index => $key ) {
			$by_key[ (string) $key ][] = (int) $index;
		}
		$order = array();
		foreach ( $courses as $course ) {
			$key = self::course_key( $course );
			while ( ! empty( $by_key[ $key ] ) ) {
				$order[] = array_shift( $by_key[ $key ] );
			}
		}
		for ( $i = 0; $i < $total; $i++ ) {
			if ( ! in_array( $i, $order, true ) ) {
				$order[] = $i;
			}
		}
		$labels = array();
		foreach ( $order as $n => $index ) {
			$labels[ $index ] = array( 'label' => 'A-' . ( $n + 1 ), 'course_key' => (string) ( $course_keys[ $index ] ?? '' ) );
		}
		return $labels;
	}

	public static function course_key( array $course ): string {
		return (string) ( $course['course_key'] ?? ( 'learndash:' . (int) ( $course['course_id'] ?? 0 ) ) );
	}

	/** Small "Attachment A-n" label at the top of each appended certificate page (inside the top 6 mm). */
	public static function stamp_attachment( $pdf, string $label, string $name, int $page, int $pages ): void {
		$width = (float) $pdf->getPageWidth();
		$break = (bool) $pdf->getAutoPageBreak();
		$pdf->SetAutoPageBreak( false );
		$pdf->SetFont( 'helvetica', '', 7 );
		$pdf->SetTextColor( 70, 70, 70 );
		$text = 'Attachment ' . $label . ( $pages > 1 ? ' (page ' . $page . ' of ' . $pages . ')' : '' ) . ( '' !== $name ? ' · ' . $name : '' );
		$pdf->SetXY( $width - 110, 2.5 );
		$pdf->Cell( 104, 3.5, $text, 0, 0, 'R' );
		$pdf->SetTextColor( 0, 0, 0 );
		$pdf->SetAutoPageBreak( $break, 15 );
	}

	/* ----------------------------------------------------------------- page furniture */

	private static function draw_first_header( $pdf, string $org, string $subtitle ): void {
		$y      = self::HEAD_Y;
		$x      = self::MARGIN;
		$text_x = $x + 12;
		$logo   = GHCA_Dashboard_Branding::get_pdf_logo_source();
		$drawn  = false;
		if ( $logo ) {
			try {
				$pdf->Image( $logo, $x, $y, 0, 10, '', '', '', false, 300 );
				$right  = (float) $pdf->getImageRBX();
				$text_x = ( $right > $x ? $right : $x + 10 ) + 3;
				$drawn  = true;
			} catch ( \Throwable $e ) {
				// A missing logo must not prevent generation; the monogram is used instead.
			}
		}
		if ( ! $drawn ) {
			list( $r, $g, $b ) = self::rgb( self::brand_colour() );
			$pdf->SetFillColor( $r, $g, $b );
			$pdf->RoundedRect( $x, $y, 9, 9, 1.6, '1111', 'F' );
			$pdf->SetTextColor( 255, 255, 255 );
			$pdf->SetFont( 'helvetica', 'B', 12 );
			$pdf->SetXY( $x, $y + 0.4 );
			$pdf->Cell( 9, 8.2, self::monogram( $org ), 0, 0, 'C' );
			$pdf->SetTextColor( 0, 0, 0 );
		}
		$pdf->SetFont( 'helvetica', 'B', 12 );
		$pdf->SetXY( $text_x, $y - 0.5 );
		$pdf->Cell( 110, 5.5, '' !== $org ? $org : 'Agency', 0, 0, 'L' );
		$pdf->SetFont( 'helvetica', '', 8.5 );
		$pdf->SetXY( $text_x, $y + 5 );
		$pdf->Cell( 110, 4.5, $subtitle, 0, 0, 'L' );
		$pdf->SetLineWidth( 0.55 );
		$pdf->Line( self::MARGIN, $y + 11.5, (float) $pdf->getPageWidth() - self::MARGIN, $y + 11.5 );
		$pdf->SetLineWidth( 0.2 );
		$pdf->SetY( $y + 16 );
	}

	/** Page numbers are known only once the summary is laid out: the generated line and running headers go on last. */
	private static function stamp_headers( $pdf, int $first, string $org, string $name, array $context ): void {
		$last  = (int) $pdf->getPage();
		$count = max( 1, $last - $first + 1 );
		$user  = '';
		if ( function_exists( 'wp_get_current_user' ) ) {
			$current = wp_get_current_user();
			$user    = $current && ! empty( $current->display_name ) ? (string) $current->display_name : '';
		}
		$generated = 'Generated ' . wp_date( 'M j, Y, g:i A', time() );
		$width     = (float) $pdf->getPageWidth();
		$pdf->SetAutoPageBreak( false );
		for ( $page = $first; $page <= $last; $page++ ) {
			$pdf->setPage( $page );
			$n = $page - $first + 1;
			$pdf->SetTextColor( 0, 0, 0 );
			if ( 1 === $n ) {
				$pdf->SetFont( 'helvetica', '', 8.5 );
				$pdf->SetXY( $width - self::MARGIN - 90, self::HEAD_Y - 0.5 );
				$pdf->Cell( 90, 4.5, $generated, 0, 0, 'R' );
				$pdf->SetXY( $width - self::MARGIN - 90, self::HEAD_Y + 5 );
				$pdf->Cell( 90, 4.5, ( '' !== $user ? 'by ' . $user . ' · ' : '' ) . 'page 1 of ' . $count, 0, 0, 'R' );
				continue;
			}
			$pdf->SetFont( 'helvetica', 'B', 9 );
			$pdf->SetXY( self::MARGIN, self::HEAD_Y );
			$pdf->Cell( 130, 5, ( '' !== $org ? $org . ' · ' : '' ) . $name, 0, 0, 'L' );
			$pdf->SetFont( 'helvetica', '', 9 );
			$pdf->SetXY( $width - self::MARGIN - 50, self::HEAD_Y );
			$pdf->Cell( 50, 5, 'page ' . $n . ' of ' . $count, 0, 0, 'R' );
			$pdf->SetLineWidth( 0.55 );
			$pdf->Line( self::MARGIN, self::HEAD_Y + 6.5, $width - self::MARGIN, self::HEAD_Y + 6.5 );
			$pdf->SetLineWidth( 0.2 );
		}
		$pdf->setPage( $last );
		$pdf->lastPage();
	}

	/* ----------------------------------------------------------------- page 1 */

	private static function employee_block( array $data, string $name, array $context ): string {
		$meta = array();
		if ( '' !== trim( (string) ( $data['role'] ?? '' ) ) ) {
			$meta[] = esc_html( (string) $data['role'] );
		}
		if ( '' !== trim( (string) ( $context['group'] ?? '' ) ) ) {
			$meta[] = 'Group: ' . esc_html( (string) $context['group'] );
		}
		return '<p style="font-size:8.5pt;"><span style="letter-spacing:1px;"><strong>EMPLOYEE</strong></span><br><span style="font-size:20pt;"><strong>' . esc_html( '' !== $name ? $name : 'Unnamed employee' ) . '</strong></span>'
			. ( $meta ? '<br><span style="font-size:10pt;">' . implode( ' · ', $meta ) . '</span>' : '' ) . '</p><div style="font-size:5pt;">&nbsp;</div>';
	}

	/** Four figures in a heavy-ruled box. */
	private static function summary_box( array $cells ): string {
		$html = '<table cellpadding="7" cellspacing="0" style="border:0.6mm solid #111111;font-size:9.5pt;"><tr>';
		foreach ( $cells as $cell ) {
			$html .= '<td width="25%"><strong>' . esc_html( $cell[0] ) . '</strong><br>' . $cell[1] . '</td>';
		}
		return $html . '</tr></table><br>';
	}

	private static function orientation_summary( array $data, array $context ): string {
		$doh        = self::parse_date( (string) ( $data['doh'] ?? '' ) );
		$completed  = self::parse_date( (string) ( $data['completion_date'] ?? '' ) );
		$start_cell = $doh ? esc_html( self::fmt( $doh ) ) : 'Not verified';
		$checked    = self::checked_by( (int) ( $context['user_id'] ?? 0 ) );
		if ( $doh && '' !== $checked ) {
			$start_cell .= '<br><span style="font-size:8pt;">' . esc_html( $checked ) . '</span>';
		}
		$days_cell = '—';
		if ( $doh && $completed ) {
			$days_cell = '<span style="font-size:16pt;"><strong>' . (int) self::days( $doh, $completed ) . '</strong></span> of ' . self::ORIENTATION_DAYS;
		} elseif ( $doh ) {
			$days_cell = 'Day ' . (int) self::days( $doh, new DateTimeImmutable( 'now', wp_timezone() ) ) . ' of ' . self::ORIENTATION_DAYS . '<br><span style="font-size:8pt;">not complete</span>';
		}
		return self::summary_box( array(
			array( 'Verified start', $start_cell ),
			array( 'Orientation completed', $completed ? esc_html( self::fmt( $completed ) ) : 'Not complete' ),
			array( 'Days to complete', $days_cell ),
			array( '30-day verdict', self::verdict_badge( $data, $doh, $completed ) ),
		) );
	}

	private static function verdict_badge( array $data, ?DateTimeImmutable $doh, ?DateTimeImmutable $completed ): string {
		$answer = (string) ( $data['completed_within_30'] ?? '' );
		if ( 'Yes' === $answer ) {
			return self::badge( 'completed', 'Within 30 days' );
		}
		if ( 'Missing evidence' === $answer ) {
			return self::badge( 'missing', 'Missing evidence' );
		}
		if ( $completed ) {
			return self::badge( 'overdue', 'Over 30 days' );
		}
		if ( $doh && self::days( $doh, new DateTimeImmutable( 'now', wp_timezone() ) ) > self::ORIENTATION_DAYS ) {
			return self::badge( 'overdue', 'Not completed within 30 days' );
		}
		return self::badge( 'progress', 'Not complete' );
	}

	/** @return array{0:string,1:string,2:string} [kind, word, heading] */
	public static function annual_status( array $data ): array {
		$styles = array(
			'compliant'        => array( 'completed', 'Compliant' ),
			'in_progress'      => array( 'progress', 'In Progress' ),
			'noncompliant'     => array( 'overdue', 'Non-Compliant' ),
			'missing_evidence' => array( 'missing', 'Missing Evidence — Review Required' ),
			'needs_review'     => array( 'review', 'Needs Review — Topic Coverage Unresolved' ),
		);
		$style   = $styles[ $data['annual_status'] ?? '' ] ?? $styles['in_progress'];
		$heading = 'Overall annual status';
		if ( 'needs_review' === ( $data['annual_status'] ?? '' ) && ! empty( $data['isp_manual_delivery'] ) && empty( $data['evidence_issues'] ) && (float) ( $data['total_hrs'] ?? 0 ) >= self::required( $data ) ) {
			$other = array_intersect_key( (array) ( $data['category_statuses'] ?? array() ), array_fill_keys( array( 'person_centered', 'abuse_prevention', 'individual_rights', 'reporting_incidents', 'behavior_supports' ), true ) );
			if ( 5 === count( $other ) && ! array_diff( $other, array( 'Completed', 'Not applicable' ) ) ) {
				// Report the split scope, without turning unverified ISP evidence into compliance.
				$heading = 'Annual training summary';
				$style   = array( 'review', 'Recorded hours and other required topics met — ISP handled manually / in person' );
			}
		}
		return array( $style[0], $style[1], $heading );
	}

	private static function annual_summary( array $data, bool $reporting ): string {
		$start = self::parse_date( (string) ( $data['start_date'] ?? '' ) );
		$end   = self::parse_date( (string) ( $data['end_date'] ?? '' ) );
		$range = ( $start ? self::fmt( $start ) : (string) ( $data['start_date'] ?? '' ) ) . ' – ' . ( $end ? self::fmt( $end ) : (string) ( $data['end_date'] ?? '' ) );
		$hours = '<span style="font-size:16pt;"><strong>' . esc_html( self::hours( (float) ( $data['total_hrs'] ?? 0 ) ) ) . '</strong></span>';
		if ( $reporting ) {
			return '<h3>Audit Reporting Period — Training Evidence</h3>' . self::summary_box( array(
				array( 'Period reviewed (inclusive)', esc_html( $range ) ),
				array( 'Recorded hours', $hours . ' h' ),
				array( '6100 topic hours', esc_html( self::hours( (float) ( $data['total_annual_hrs'] ?? 0 ) ) ) . ' h' ),
				array( 'Result', 'Evidence report only — no compliance verdict for a custom period' ),
			) );
		}
		$cycle  = GHCA_ACD_Settings::annual_cycle_label( (string) ( $data['annual_cycle'] ?? '' ) );
		$status = self::annual_status( $data );
		return self::summary_box( array(
			array( 'Annual window', esc_html( $range ) . '<br><span style="font-size:8pt;">Annual Cycle Rule: ' . esc_html( $cycle ) . '</span>' ),
			array( 'Recorded hours', $hours . ( self::required( $data ) > 0 ? ' of ' . self::required( $data ) : ' (annual hours not required)' ) . ( '' !== (string) ( $data['required_annual_label'] ?? '' ) ? '<br><span style="font-size:8pt;">' . esc_html( (string) $data['required_annual_label'] ) . '</span>' : '' ) ),
			array( '6100 topic hours', '<span style="font-size:16pt;"><strong>' . esc_html( self::hours( (float) ( $data['total_annual_hrs'] ?? 0 ) ) ) . '</strong></span> h' ),
			array( $status[2], self::badge( $status[0], $status[1] ) ),
		) );
	}

	private static function areas_table( array $data, bool $annual, bool $reporting, array $attachments ): string {
		$areas = $annual ? self::ANNUAL_AREAS : self::ORIENTATION_AREAS;
		$hours = (array) ( $data['requirement_hours'] ?? array() );
		$html  = '<h2 style="font-size:13pt;">' . ( $annual ? 'Annual ODP requirement areas' : 'Orientation areas' ) . '</h2>'
			. '<table cellpadding="5" cellspacing="0" style="font-size:9pt;"><thead><tr style="background-color:#f2f2f2;">'
			. '<th width="30%" style="border-bottom:0.4mm solid #111111;"><strong>' . ( $annual ? 'Area (ODP annual)' : 'Area (ODP orientation)' ) . '</strong></th>'
			. '<th width="28%" style="border-bottom:0.4mm solid #111111;"><strong>Status</strong></th>'
			. '<th width="14%" style="border-bottom:0.4mm solid #111111;"><strong>' . ( $annual ? 'Hours Counted Here' : 'Completed' ) . '</strong></th>'
			. '<th width="28%" style="border-bottom:0.4mm solid #111111;"><strong>Evidence</strong></th></tr></thead><tbody>';
		foreach ( $areas as $key => $label ) {
			list( $kind, $word, $note ) = self::area_status( $data, $key, $annual );
			$status = self::badge( $kind, $word ) . ( '' !== $note ? '<br><span style="font-size:7.5pt;">' . esc_html( $note ) . '</span>' : '' );
			if ( $annual ) {
				$third = esc_html( self::hours( (float) ( $hours[ $key ]['achieved'] ?? 0 ) ) ) . ' hrs';
			} else {
				$date  = (int) ( $hours[ $key ]['date'] ?? 0 );
				$third = $date > 0 ? esc_html( wp_date( 'M j, Y', $date ) ) : '—';
			}
			$html .= '<tr nobr="true"><td width="30%" style="border-bottom:0.2mm solid #cccccc;">' . esc_html( $label ) . '</td>'
				. '<td width="28%" style="border-bottom:0.2mm solid #cccccc;">' . $status . '</td>'
				. '<td width="14%" style="border-bottom:0.2mm solid #cccccc;">' . $third . '</td>'
				. '<td width="28%" style="border-bottom:0.2mm solid #cccccc;">' . self::area_evidence( $data, $key, $attachments ) . '</td></tr>';
		}
		$html .= '</tbody></table>';

		// Agency-mandated rows, as before.
		if ( ! empty( $data['agency_selected_courses'] ) || ! empty( $data['communication_manual_delivery'] ) ) {
			$html .= '<h3 style="font-size:10.5pt;">Agency Mandated Training/compliance</h3><table cellpadding="5" cellspacing="0" style="font-size:9pt;">';
			if ( ! empty( $data['communication_manual_delivery'] ) ) {
				$confirmed = $data['agency_training']['communication'] ?? array();
				$html .= '<tr nobr="true"><td width="44%" style="border-bottom:0.2mm solid #cccccc;">Communication Profile Training</td><td width="40%" style="border-bottom:0.2mm solid #cccccc;">'
					. ( $confirmed ? self::badge( 'completed', 'Completed in person — agency confirmed' ) : self::badge( 'review', 'Handled by agency — not yet confirmed' ) )
					. '</td><td width="16%" style="border-bottom:0.2mm solid #cccccc;">' . esc_html( self::hours( (float) ( $confirmed['hours'] ?? 0 ) ) ) . ' hrs</td></tr>';
			}
			foreach ( (array) $data['agency_selected_courses'] as $course ) {
				$date = (int) ( $course['date'] ?? 0 );
				$html .= '<tr nobr="true"><td width="44%" style="border-bottom:0.2mm solid #cccccc;">' . esc_html( (string) $course['title'] ) . '</td><td width="40%" style="border-bottom:0.2mm solid #cccccc;">'
					. ( ! empty( $course['included'] ) ? self::badge( 'completed', 'Completed' ) . ( $date > 0 ? ' ' . esc_html( wp_date( 'M j, Y', $date ) ) : '' ) : self::badge( 'missing', 'No included evidence' ) )
					. '</td><td width="16%" style="border-bottom:0.2mm solid #cccccc;">' . esc_html( self::hours( (float) ( $course['hours'] ?? 0 ) ) ) . ' hrs</td></tr>';
			}
			$html .= '</table>';
		}

		if ( $annual && ( ! empty( $data['isp_manual_delivery'] ) || ! empty( $data['agency_training']['isp'] ) ) ) {
			$html .= '<p><strong>ISP training delivery:</strong> Supporting records are maintained by the agency and provided separately for audit.</p>';
		} elseif ( $annual && ! empty( $data['external_isp_evidence'] ) ) {
			$html .= '<p><strong>ISP evidence to be supplied separately — agency review required</strong><br>' . esc_html( (string) ( $data['category_notes']['individual_plan'] ?? '' ) ) . '</p>';
		}
		if ( ! $reporting ) {
			$html .= '<p style="font-size:7.5pt;">Topic status reflects mapped evidence, not independent verification of course content. Each course credits hours to its primary category only; additional covered topics may show Completed with zero hours counted here. Unmapped topics need review, not an automatic failure. Conditional applicability is reviewed by an administrator for this employee and period.</p>';
		}
		if ( ! $annual ) {
			$html .= '<p style="font-size:7.5pt;">Orientation is assessed on completion of each required content area and on the timing rules below. Time spent is recorded for reference and is not a regulatory threshold.</p>';
		}
		return $html;
	}

	/**
	 * One area's status as [kind, word, note]: the word prints in the badge, any recorded note under it.
	 *
	 * @return array{0:string,1:string,2:string}
	 */
	public static function area_status( array $data, string $key, bool $annual ): array {
		$text = GHCA_Audit_Calculator::category_display( $data, $key );
		if ( $annual && 'individual_plan' === $key ) {
			if ( ! empty( $data['agency_training']['isp'] ) && ( $data['category_statuses']['individual_plan'] ?? '' ) === 'Completed — manually verified' ) {
				$text = 'Completed in person — agency confirmed';
			} elseif ( ! empty( $data['isp_manual_delivery'] ) ) {
				$text = 'Handled manually / in person';
			} elseif ( ! empty( $data['external_isp_evidence'] ) ) {
				$text = (string) ( $data['category_statuses'][ $key ] ?? 'Needs review' );
			}
		}
		$word = (string) ( $data['category_statuses'][ $key ] ?? '' );
		$note = '';
		if ( '' !== $word && 0 === strpos( $text, $word . ' — ' ) ) {
			$note = substr( $text, strlen( $word . ' — ' ) );
		} else {
			$word = $text;
		}
		return array( self::kind_for( $text ), $word, $note );
	}

	/** Course titles that evidence one area, with their attachment numbers. */
	private static function area_evidence( array $data, string $area, array $attachments ): string {
		$items = array();
		foreach ( (array) ( $data['raw_completed_courses'] ?? array() ) as $course ) {
			$covered = array_merge( array( (string) ( $course['category'] ?? '' ) ), array_map( 'strval', (array) ( $course['categories'] ?? array() ) ) );
			if ( ! in_array( $area, $covered, true ) ) {
				continue;
			}
			$refs  = self::refs_for( $course, $attachments );
			$items[] = esc_html( (string) ( $course['title'] ?? '' ) ) . ( 'external' === (string) ( $course['source'] ?? '' ) ? ' (outside training)' : '' ) . ( $refs ? ' · ' . esc_html( implode( ', ', $refs ) ) : '' );
		}
		if ( 'individual_plan' === $area && ( ! empty( $data['isp_manual_delivery'] ) || ! empty( $data['agency_training']['isp'] ) ) ) {
			$items[] = 'Agency records (provided separately)';
		}
		return $items ? implode( '<br>', array_unique( $items ) ) : '—';
	}

	private static function orientation_key_dates( array $data, array $context ): string {
		$row = static function ( string $label, string $value ): string {
			return '<tr nobr="true"><th width="38%" style="border-bottom:0.2mm solid #cccccc;"><strong>' . esc_html( $label ) . '</strong></th><td width="62%" style="border-bottom:0.2mm solid #cccccc;">' . $value . '</td></tr>';
		};
		$doh       = self::parse_date( (string) ( $data['doh'] ?? '' ) );
		$completed = self::parse_date( (string) ( $data['completion_date'] ?? '' ) );
		$service   = self::date_or_raw( $data['first_service_date'] ?? '' );
		$alone     = self::date_or_raw( $data['worked_alone_date'] ?? '' );
		$within    = (string) ( $data['completed_within_30'] ?? '' );
		$verdict   = (string) ( $data['worked_alone_compliant'] ?? '' );
		$alone_cell = '' !== $alone ? esc_html( $alone ) : 'Not recorded';
		if ( 'Yes' === $verdict ) {
			$alone_cell .= ' — on/after orientation completion';
		} elseif ( 'No (Violation)' === $verdict ) {
			$alone_cell .= ' — before orientation completion';
		}
		$html = '<h2 style="font-size:13pt;">Key dates</h2><table cellpadding="5" cellspacing="0" style="font-size:9.5pt;">'
			. $row( 'Verified date of hire (DOH)', $doh ? esc_html( self::fmt( $doh ) ) : 'Not verified' )
			. $row( 'Orientation completion date', $completed ? esc_html( self::fmt( $completed ) ) : 'Not complete' )
			. $row( 'Completed within 30 days of verified date of hire?', self::badge( 'Yes' === $within ? 'completed' : ( 'Missing evidence' === $within ? 'missing' : ( $completed ? 'overdue' : 'progress' ) ), $within ) )
			. $row( 'First service date', '' !== $service ? esc_html( $service ) : 'Not recorded' )
			. $row( 'Worked alone from', $alone_cell )
			. $row( 'Completed before working alone?', self::badge( self::kind_for( $verdict ), $verdict ) );
		$registered = self::registered( (int) ( $context['user_id'] ?? 0 ) );
		if ( '' !== $registered ) {
			$html .= $row( 'Account registered (not used for audit)', esc_html( $registered ) );
		}
		$html .= '</table>';

		$program = (array) ( $data['new_hire_program'] ?? array() );
		if ( ! empty( $program['active'] ) ) {
			$titles = static function ( array $items ): string {
				return esc_html( implode( '; ', array_map( static function ( $item ) { return $item['title'] . ' (#' . (int) $item['course_id'] . ')'; }, $items ) ) );
			};
			$html .= '<p style="font-size:8pt;"><strong>New-hire program courses:</strong> '
				. ( ! empty( $program['complete'] ) && empty( $program['missing_dates'] ) ? 'All required courses are complete.' : 'Not complete.' );
			if ( ! empty( $program['outstanding'] ) ) {
				$html .= '<br><strong>Outstanding:</strong> ' . $titles( $program['outstanding'] );
			}
			if ( ! empty( $program['missing_dates'] ) ) {
				$html .= '<br><strong>Completed without a recorded date:</strong> ' . $titles( $program['missing_dates'] );
			}
			if ( ! empty( $program['unmapped_required'] ) ) {
				$html .= '<br>Required by the new-hire program but not mapped to an ODP orientation topic (not shown in the topic table): ' . $titles( $program['unmapped_required'] );
			}
			$basis = (string) ( $program['deadline_basis'] ?? '' );
			$html .= '<br>The overall completion date is the last required new-hire course completion. The 30-day result runs from the verified date of hire (regulatory basis). ';
			if ( 'hire' === $basis || 'first_service' === $basis ) {
				$html .= 'The agency\'s new-hire program deadline' . ( ! empty( $program['deadline_date'] ) ? ' (' . esc_html( (string) $program['deadline_date'] ) . ')' : '' )
					. ' is ' . (int) ( $program['deadline_days'] ?? 0 ) . ' days after the ' . ( 'first_service' === $basis ? 'first service date' : 'verified date of hire' ) . '.</p>';
			} elseif ( 'missing' === $basis ) {
				$html .= 'The agency\'s new-hire program deadline cannot be set: the verified date of hire is missing.</p>';
			} else {
				$html .= 'The agency\'s new-hire program deadline'
					. ( ! empty( $program['deadline_date'] ) ? ' (' . esc_html( (string) $program['deadline_date'] ) . ')' : '' )
					. ' is an internal target of ' . (int) ( $program['deadline_days'] ?? 0 ) . ' days from program enrollment'
					. ( ! empty( $program['enrollment_date'] ) ? ' (' . esc_html( (string) $program['enrollment_date'] ) . ')' : '' ) . ' and may differ.</p>';
			}
		}
		return $html;
	}

	private static function annual_key_figures( array $data, bool $reporting ): string {
		$row = static function ( string $label, string $value, bool $strong = false ): string {
			return '<tr nobr="true"' . ( $strong ? ' style="background-color:#f6f6f6;"' : '' ) . '><td width="62%" style="border-bottom:0.2mm solid #cccccc;"><strong>' . esc_html( $label ) . '</strong></td><td width="38%" style="border-bottom:0.2mm solid #cccccc;">' . ( $strong ? '<strong>' . $value . '</strong>' : $value ) . '</td></tr>';
		};
		$html = '<h2 style="font-size:13pt;">Hours and dates</h2><table cellpadding="5" cellspacing="0" style="font-size:9.5pt;">'
			. $row( '6100 topic training hours:', esc_html( (string) ( $data['total_annual_hrs'] ?? 0 ) ) )
			. $row( 'Additional training hours:', esc_html( (string) ( $data['additional_hrs'] ?? 0 ) ) )
			. $row( 'Total recorded training hours:', esc_html( (string) ( $data['total_hrs'] ?? 0 ) ), true );
		$doh     = self::parse_date( (string) ( $data['doh'] ?? '' ) );
		$service = self::date_or_raw( $data['first_service_date'] ?? '' );
		$html   .= $row( 'Verified date of hire (DOH):', $doh ? esc_html( self::fmt( $doh ) ) : 'Not verified' )
			. $row( 'First service date:', '' !== $service ? esc_html( $service ) : 'Not recorded' );
		return $html . '</table>';
	}

	/* ----------------------------------------------------------------- page 2+ */

	private static function course_records( array $data, bool $annual, array $attachments ): string {
		$th   = static function ( string $label, string $width ): string {
			return '<th width="' . $width . '" style="border-bottom:0.4mm solid #111111;"><strong>' . esc_html( $label ) . '</strong></th>';
		};
		$td   = static function ( string $value, string $width ): string {
			return '<td width="' . $width . '" style="border-bottom:0.2mm solid #cccccc;">' . $value . '</td>';
		};
		$rows = '';
		foreach ( (array) ( $data['raw_completed_courses'] ?? array() ) as $course ) {
			$title = esc_html( (string) ( $course['title'] ?? '' ) );
			if ( 'external' === (string) ( $course['source'] ?? '' ) ) {
				$title .= '<br><span style="font-size:7.5pt;">Outside training' . ( ! empty( $course['provider'] ) ? ' · ' . esc_html( (string) $course['provider'] ) : '' ) . '</span>';
			} elseif ( 'manual' === (string) ( $course['entry_method'] ?? '' ) ) {
				$title .= '<br><span style="font-size:7.5pt;">' . esc_html( GHCA_Audit_PDF::manual_entry_label( $course ) ) . '</span>';
			}
			$date  = (int) ( $course['date'] ?? 0 );
			$refs  = self::refs_for( $course, $attachments );
			$rows .= '<tr nobr="true">' . $td( $title, '36%' ) . $td( self::badge( 'completed', 'Completed' ), '20%' )
				. $td( $date > 0 ? esc_html( wp_date( 'M j, Y', $date ) ) : '—', '16%' )
				. $td( esc_html( self::duration( (float) ( $course['credit_hours'] ?? 0 ) ) ), '13%' )
				. $td( $refs ? esc_html( implode( ', ', $refs ) ) : '—', '15%' ) . '</tr>';
		}
		$program = (array) ( $data['new_hire_program'] ?? array() );
		if ( ! $annual && ! empty( $program['active'] ) ) {
			foreach ( (array) ( $program['outstanding'] ?? array() ) as $item ) {
				$rows .= '<tr nobr="true">' . $td( esc_html( (string) $item['title'] ) . '<br><span style="font-size:7.5pt;">New-hire program</span>', '36%' ) . $td( self::badge( 'notstarted', 'Not complete' ), '20%' ) . $td( '—', '16%' ) . $td( '—', '13%' ) . $td( '—', '15%' ) . '</tr>';
			}
			foreach ( (array) ( $program['missing_dates'] ?? array() ) as $item ) {
				$rows .= '<tr nobr="true">' . $td( esc_html( (string) $item['title'] ) . '<br><span style="font-size:7.5pt;">New-hire program</span>', '36%' ) . $td( self::badge( 'missing', 'No recorded date' ), '20%' ) . $td( '—', '16%' ) . $td( '—', '13%' ) . $td( '—', '15%' ) . '</tr>';
			}
		}
		if ( '' === $rows ) {
			$rows = '<tr><td width="100%" style="border-bottom:0.2mm solid #cccccc;">No completed training is recorded for this ' . ( $annual ? 'period' : 'orientation' ) . '.</td></tr>';
		}
		return '<h2 style="font-size:13pt;">Course records</h2><table cellpadding="5" cellspacing="0" style="font-size:9pt;"><thead><tr style="background-color:#f2f2f2;">'
			. $th( 'Course', '36%' ) . $th( 'Status', '20%' ) . $th( 'Completed', '16%' ) . $th( $annual ? 'Hours' : 'Time', '13%' ) . $th( 'Certificate', '15%' )
			. '</tr></thead><tbody>' . $rows . '</tbody></table>';
	}

	/** Every open item found on page 1, in one list; "None" only when there is genuinely nothing open. */
	public static function gap_items( array $data, bool $annual, bool $reporting ): array {
		$gaps  = array();
		foreach ( (array) ( $data['evidence_issues'] ?? array() ) as $issue ) {
			$gaps[] = 'Evidence review: ' . (string) $issue;
		}
		foreach ( $annual ? self::ANNUAL_AREAS : self::ORIENTATION_AREAS as $key => $label ) {
			$status = self::area_status( $data, $key, $annual );
			if ( ! in_array( $status[0], array( 'completed', 'na' ), true ) ) {
				$gaps[] = $label . ': ' . $status[1];
			}
		}
		foreach ( (array) ( $data['agency_selected_courses'] ?? array() ) as $course ) {
			if ( empty( $course['included'] ) ) {
				$gaps[] = (string) $course['title'] . ': no included evidence';
			}
		}
		if ( $annual ) {
			if ( ! $reporting && 'compliant' !== ( $data['annual_status'] ?? '' ) ) {
				$status = self::annual_status( $data );
				$gaps[] = $status[2] . ': ' . $status[1];
			}
			return $gaps;
		}
		$program = (array) ( $data['new_hire_program'] ?? array() );
		foreach ( (array) ( $program['outstanding'] ?? array() ) as $item ) {
			$gaps[] = 'New-hire program course not complete: ' . (string) $item['title'];
		}
		foreach ( (array) ( $program['missing_dates'] ?? array() ) as $item ) {
			$gaps[] = 'New-hire program course without a recorded date: ' . (string) $item['title'];
		}
		if ( 'Yes' !== ( $data['completed_within_30'] ?? '' ) ) {
			$gaps[] = 'Completed within 30 days of verified date of hire: ' . (string) ( $data['completed_within_30'] ?? '' );
		}
		if ( in_array( (string) ( $data['worked_alone_compliant'] ?? '' ), array( 'No (Violation)', 'Missing evidence', 'Not complete' ), true ) ) {
			$gaps[] = 'Completed before working alone: ' . (string) $data['worked_alone_compliant'];
		}
		return $gaps;
	}

	private static function gaps( array $data, bool $annual, bool $reporting ): string {
		$gaps = self::gap_items( $data, $annual, $reporting );
		$html = '<h2 style="font-size:13pt;">Gaps found</h2><table cellpadding="5" cellspacing="0" style="font-size:9.5pt;border:0.2mm solid #cccccc;"><tr><td>';
		if ( ! $gaps ) {
			$html .= $annual
				? ( $reporting ? 'None recorded in this period.' : ( self::required( $data ) > 0 ? 'None. All annual areas are complete or not applicable, and recorded hours meet the ' . self::required( $data ) . '-hour requirement.' : 'None. All annual areas are complete or not applicable; annual hours are not required for this employee.' ) )
				: 'None. All orientation areas complete and dates verified.';
		} else {
			$html .= '<ul>';
			foreach ( $gaps as $gap ) {
				$html .= '<li>' . esc_html( $gap ) . '</li>';
			}
			$html .= '</ul>';
		}
		return $html . '</td></tr></table>';
	}

	private static function attachments_list( array $data, array $attachments ): string {
		$html = '<h2 style="font-size:13pt;">Attached certificates</h2>';
		if ( ! $attachments ) {
			return $html . '<p style="font-size:9.5pt;">No certificates are attached to this packet.</p>';
		}
		$courses = array();
		foreach ( (array) ( $data['raw_completed_courses'] ?? array() ) as $course ) {
			$courses[ self::course_key( $course ) ] = $course;
		}
		$rows = $attachments;
		uasort( $rows, static function ( $a, $b ) { return (int) substr( $a['label'], 2 ) <=> (int) substr( $b['label'], 2 ); } );
		$html .= '<ol style="font-size:9.5pt;">';
		foreach ( $rows as $row ) {
			$course = $courses[ $row['course_key'] ] ?? null;
			if ( $course ) {
				$date  = (int) ( $course['date'] ?? 0 );
				$where = 'external' === (string) ( $course['source'] ?? '' ) ? 'outside training, approved' : 'LearnDash';
				$text  = (string) ( $course['title'] ?? '' ) . ' — ' . $where . ( $date > 0 ? ', ' . wp_date( 'M j, Y', $date ) : '' );
			} else {
				$text = 'Certificate';
			}
			$html .= '<li>' . esc_html( $text ) . ' (attachment ' . esc_html( $row['label'] ) . ')</li>';
		}
		return $html . '</ol>';
	}

	private static function course_details( array $data ): string {
		$html = '<h2 style="font-size:13pt;">Included Course Details</h2><table cellpadding="5" cellspacing="0" style="font-size:9pt;"><thead><tr style="background-color:#f2f2f2;">'
			. '<th width="25%" style="border-bottom:0.4mm solid #111111;"><strong>Course</strong></th><th width="45%" style="border-bottom:0.4mm solid #111111;"><strong>Course Details</strong></th>'
			. '<th width="16%" style="border-bottom:0.4mm solid #111111;"><strong>Completed</strong></th><th width="14%" style="border-bottom:0.4mm solid #111111;"><strong>Credit Hrs Achieved</strong></th></tr></thead><tbody>';
		foreach ( (array) $data['raw_completed_courses'] as $course ) {
			$description = ! empty( $course['description'] ) ? esc_html( (string) $course['description'] ) : '<span style="color:#666666;">Not provided</span>';
			$html .= '<tr nobr="true"><td width="25%" style="border-bottom:0.2mm solid #cccccc;"><strong>' . esc_html( (string) $course['title'] ) . '</strong></td>'
				. '<td width="45%" style="border-bottom:0.2mm solid #cccccc;">' . $description . '</td>'
				. '<td width="16%" style="border-bottom:0.2mm solid #cccccc;">' . esc_html( wp_date( 'M j, Y', (int) $course['date'] ) ) . '</td>'
				. '<td width="14%" style="border-bottom:0.2mm solid #cccccc;">' . esc_html( self::hours( (float) $course['credit_hours'] ) ) . '</td></tr>';
		}
		return $html . '</tbody></table>';
	}

	private static function signature_block(): string {
		return '<br><br><table cellpadding="4" cellspacing="0" style="font-size:9.5pt;" nobr="true"><tr>'
			. '<td width="47%" style="border-bottom:0.3mm solid #111111;height:30px;"></td><td width="6%"></td><td width="47%" style="border-bottom:0.3mm solid #111111;height:30px;"></td></tr>'
			. '<tr><td width="47%">Reviewed by (name, title)</td><td width="6%"></td><td width="47%">Date</td></tr></table>';
	}

	/* ----------------------------------------------------------------- helpers */

	/** The employee's annual hours requirement (24 or 12), as the calculator applied it. */
	public static function required( array $data ): int {
		return (int) ( $data['required_annual_hrs'] ?? 24 );
	}

	public static function kind_for( string $text ): string {
		$t = strtolower( trim( $text ) );
		if ( 0 === strpos( $t, 'completed' ) || 0 === strpos( $t, 'recorded in period' ) || 'yes' === $t ) {
			return 'completed';
		}
		if ( 0 === strpos( $t, 'not applicable' ) || 'n/a' === $t ) {
			return 'na';
		}
		if ( 0 === strpos( $t, 'missing' ) || 0 === strpos( $t, 'no completion recorded' ) ) {
			return 'missing';
		}
		if ( 0 === strpos( $t, 'no (violation)' ) || 'no' === $t ) {
			return 'overdue';
		}
		if ( 0 === strpos( $t, 'not complete' ) ) {
			return 'progress';
		}
		return 'review';
	}

	public static function badge( string $kind, string $word ): string {
		$style = self::KINDS[ $kind ] ?? self::KINDS['review'];
		$icon  = self::has_symbol_font()
			? '<span style="font-family:dejavusans;">' . $style[0] . '</span>'
			: esc_html( self::ASCII[ $kind ] ?? '?' );
		$border = in_array( $kind, array( 'review', 'notstarted' ), true ) ? 'border:0.2mm solid ' . $style[1] . ';' : '';
		return '<span style="color:' . $style[1] . ';background-color:' . $style[2] . ';' . $border . '">&nbsp;' . $icon . '&nbsp;' . esc_html( '' !== $word ? $word : 'Not recorded' ) . '&nbsp;</span>';
	}

	private static function has_symbol_font(): bool {
		static $has = null;
		if ( null === $has ) {
			$has = defined( 'K_PATH_FONTS' ) && is_file( K_PATH_FONTS . 'dejavusans.php' );
		}
		return $has;
	}

	private static function refs_for( array $course, array $attachments ): array {
		$key  = self::course_key( $course );
		$refs = array();
		foreach ( $attachments as $row ) {
			if ( $row['course_key'] === $key ) {
				$refs[] = $row['label'];
			}
		}
		return $refs;
	}

	private static function checked_by( int $user_id ): string {
		if ( $user_id <= 0 || ! class_exists( 'GHCA_ACD_Employment_Record' ) ) {
			return '';
		}
		$record = GHCA_ACD_Employment_Record::get( $user_id );
		if ( ! $record ) {
			return '';
		}
		$by   = function_exists( 'get_userdata' ) ? get_userdata( (int) $record['verified_by'] ) : null;
		$name = $by && ! empty( $by->display_name ) ? (string) $by->display_name : 'user #' . (int) $record['verified_by'];
		return 'Checked by ' . $name . ', ' . wp_date( 'M j, Y', (int) $record['verified_at'] );
	}

	private static function registered( int $user_id ): string {
		if ( $user_id <= 0 || ! function_exists( 'get_userdata' ) ) {
			return '';
		}
		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->user_registered ) ) {
			return '';
		}
		$ts = strtotime( (string) $user->user_registered . ' UTC' );
		return $ts ? wp_date( 'M j, Y', $ts ) : '';
	}

	public static function parse_date( string $value ): ?DateTimeImmutable {
		$value = trim( $value );
		foreach ( array( '!m/d/Y', '!Y-m-d' ) as $format ) {
			$date = DateTimeImmutable::createFromFormat( $format, $value, new DateTimeZone( 'UTC' ) );
			if ( $date && $date->format( substr( $format, 1 ) ) === $value ) {
				return $date;
			}
		}
		return null;
	}

	private static function date_or_raw( $value ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		$date  = self::parse_date( $value );
		return $date ? self::fmt( $date ) : $value;
	}

	private static function fmt( DateTimeImmutable $date ): string {
		return $date->format( 'M j, Y' );
	}

	/** Calendar days between two dates (hire day is day 0). */
	private static function days( DateTimeImmutable $from, DateTimeImmutable $to ): int {
		$a = new DateTimeImmutable( $from->format( 'Y-m-d' ), new DateTimeZone( 'UTC' ) );
		$b = new DateTimeImmutable( $to->format( 'Y-m-d' ), new DateTimeZone( 'UTC' ) );
		return (int) $a->diff( $b )->format( '%r%a' );
	}

	public static function hours( float $hours ): string {
		return rtrim( rtrim( number_format( $hours, 2, '.', '' ), '0' ), '.' );
	}

	public static function duration( float $hours ): string {
		if ( $hours <= 0 ) {
			return '—';
		}
		$minutes = (int) round( $hours * 60 );
		if ( $minutes < 60 ) {
			return $minutes . ' min';
		}
		return intdiv( $minutes, 60 ) . ' h' . ( $minutes % 60 ? ' ' . ( $minutes % 60 ) . ' m' : '' );
	}

	private static function brand_colour(): string {
		$colour = method_exists( 'GHCA_Dashboard_Branding', 'get' ) ? (string) ( GHCA_Dashboard_Branding::get()['primary'] ?? '' ) : '';
		return preg_match( '/^#[0-9a-fA-F]{6}$/', $colour ) ? $colour : '#176cad';
	}

	private static function rgb( string $hex ): array {
		return array( hexdec( substr( $hex, 1, 2 ) ), hexdec( substr( $hex, 3, 2 ) ), hexdec( substr( $hex, 5, 2 ) ) );
	}

	private static function monogram( string $org ): string {
		$org = trim( $org );
		if ( '' === $org ) {
			return 'A';
		}
		return function_exists( 'mb_substr' ) ? mb_strtoupper( mb_substr( $org, 0, 1 ) ) : strtoupper( substr( $org, 0, 1 ) );
	}
}
