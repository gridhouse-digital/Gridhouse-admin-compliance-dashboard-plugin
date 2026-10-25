<?php
/**
 * Immutable encrypted storage for approved external-training certificate PDFs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_External_Evidence_Store {
	const MAGIC = "GHCAE1";
	const MAX_NORMALIZED_BYTES = 8388608;

	/** @return true|WP_Error */
	public static function readiness() {
		$key = self::key();
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$base = self::base_directory();
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return new WP_Error( 'ghca_evidence_crypto_missing', __( 'OpenSSL is required for approved evidence storage.', 'ghca-acd' ) );
		}
		return true;
	}

	/**
	 * Retrieves, validates, normalizes and encrypts all certificates for one pending training record.
	 *
	 * @param array<string,mixed> $training
	 * @param array<string,string> $form
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public static function capture( array $training, array $form ) {
		$ready = self::readiness();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$response = GHCA_ACD_Jotform_Provider::get_submission( (string) $training['submission_id'] );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$submission = is_array( $response['content'] ?? null ) ? $response['content'] : array();
		$current_ownership = GHCA_ACD_Jotform_Sync::verified_ownership( $form, $submission );
		if ( (int) $current_ownership['user_id'] > 0 && (int) $current_ownership['user_id'] !== (int) $training['employee_user_id'] ) {
			return new WP_Error( 'ghca_evidence_owner_changed', __( 'The verified Jotform employee owner no longer matches this review.', 'ghca-acd' ) );
		}
		$current_fingerprint = GHCA_ACD_Jotform_Sync::submission_fingerprint( $form, $submission );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $training['source_fingerprint'] ?? '' ) ) || ! hash_equals( (string) $training['source_fingerprint'], $current_fingerprint ) ) {
			return new WP_Error( 'ghca_evidence_source_changed', __( 'The Jotform submission changed after it was indexed. Synchronize and review the new revision.', 'ghca-acd' ) );
		}
		$descriptors = self::resolve_source_descriptors( $form, $submission );
		if ( is_wp_error( $descriptors ) ) {
			return $descriptors;
		}
		$manifest = array();
		$stored = array();
		$capture_id = bin2hex( random_bytes( 16 ) );
		foreach ( $descriptors as $index => $descriptor ) {
			$fetched = self::fetch_source_descriptor( $descriptor );
			if ( is_wp_error( $fetched ) ) {
				self::remove_files( $stored );
				return $fetched;
			}
			$pdf = self::normalize_to_pdf( (string) $fetched['body'], (string) $fetched['mime'], (string) $fetched['name'] );
			if ( is_wp_error( $pdf ) ) {
				self::remove_files( $stored );
				return $pdf;
			}
			$relative = self::relative_path( (int) $training['employee_user_id'], (int) $training['id'], (string) $training['source_fingerprint'], $capture_id, $index );
			$written = self::encrypt_and_write( $relative, $pdf, self::aad( $training, $index ) );
			if ( is_wp_error( $written ) ) {
				self::remove_files( $stored );
				return $written;
			}
			$stored[] = $relative;
			$manifest[] = array(
				'file_index' => $index,
				'file_name' => substr( sanitize_file_name( (string) $fetched['name'] ), 0, 255 ),
				'media_type' => 'application/pdf',
				'bytes' => strlen( $pdf ),
				'sha256' => hash( 'sha256', $pdf ),
				'storage_path' => $relative,
				'expected_source_name' => isset( $descriptor['expected_source_name'] ) ? (string) $descriptor['expected_source_name'] : (string) $fetched['name'],
				'drive_source_name' => isset( $descriptor['drive_source_name'] ) ? (string) $descriptor['drive_source_name'] : '',
			);
			unset( $fetched, $pdf );
		}
		return $manifest;
	}

	/** @param array<string,mixed> $file @return array<string,mixed>|WP_Error */
	public static function capture_manual_upload( array $file, int $employee_id, string $capture_id ) {
		$ready = self::readiness();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$tmp = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$name = isset( $file['name'] ) ? (string) $file['name'] : '';
		$mime = isset( $file['type'] ) ? sanitize_mime_type( (string) $file['type'] ) : '';
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || $employee_id <= 0 || ! preg_match( '/^[a-f0-9]{32}$/', $capture_id ) || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return new WP_Error( 'ghca_oltl_upload_invalid', __( 'The manual-review evidence upload is invalid.', 'ghca-acd' ) );
		}
		$bytes = @file_get_contents( $tmp );
		if ( ! is_string( $bytes ) ) {
			return new WP_Error( 'ghca_oltl_upload_unreadable', __( 'The manual-review evidence could not be read.', 'ghca-acd' ) );
		}
		$pdf = self::normalize_to_pdf( $bytes, $mime, $name );
		if ( is_wp_error( $pdf ) ) {
			return $pdf;
		}
		$site = substr( hash( 'sha256', wp_normalize_path( ABSPATH ) ), 0, 16 );
		$relative = sprintf( 'oltl-evidence/%s/%d/%s.ghca', $site, $employee_id, $capture_id );
		$aad = 'ghca-oltl-v1|' . $employee_id . '|' . $capture_id;
		$written = self::encrypt_and_write( $relative, $pdf, $aad );
		if ( is_wp_error( $written ) ) {
			return $written;
		}
		return array(
			'file_name' => substr( sanitize_file_name( $name ), 0, 255 ),
			'media_type' => 'application/pdf',
			'bytes' => strlen( $pdf ),
			'sha256' => hash( 'sha256', $pdf ),
			'storage_path' => $relative,
			'aad' => $aad,
		);
	}

	/**
	 * Validates and normalizes one administrator-uploaded certificate without storing it.
	 * The caller persists the pending training row first, because the evidence path and
	 * AAD are derived from that row's id; store_manual_training() then writes the bytes.
	 *
	 * @param array<string,mixed> $file One $_FILES entry.
	 * @return array{pdf:string,name:string}|WP_Error
	 */
	public static function prepare_manual_upload( array $file ) {
		$ready = self::readiness();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$tmp  = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$name = isset( $file['name'] ) ? (string) $file['name'] : '';
		$mime = isset( $file['type'] ) ? sanitize_mime_type( (string) $file['type'] ) : '';
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return new WP_Error( 'ghca_manual_upload_invalid', __( 'The certificate upload is invalid or incomplete.', 'ghca-acd' ) );
		}
		$bytes = @file_get_contents( $tmp );
		if ( ! is_string( $bytes ) ) {
			return new WP_Error( 'ghca_manual_upload_unreadable', __( 'The certificate upload could not be read.', 'ghca-acd' ) );
		}
		$pdf = self::normalize_to_pdf( $bytes, $mime, $name );
		if ( is_wp_error( $pdf ) ) {
			return $pdf;
		}
		return array( 'pdf' => $pdf, 'name' => substr( sanitize_file_name( $name ), 0, 255 ) );
	}

	/** Deterministic identity for one manual entry: the employee plus the normalized certificate bytes. */
	public static function manual_fingerprint( int $employee_id, string $pdf ): string {
		return hash( 'sha256', 'manual|' . $employee_id . '|' . hash( 'sha256', $pdf ) );
	}

	/**
	 * Encrypts a normalized manual certificate into the standard external-evidence path so
	 * approval, packet materialization and cleanup treat it exactly like Jotform evidence.
	 *
	 * @param array<string,mixed> $training Persisted pending manual training row.
	 * @return array<int,array<string,mixed>>|WP_Error One-item manifest in capture() shape.
	 */
	public static function store_manual_training( array $training, string $pdf, string $name ) {
		$ready = self::readiness();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$capture_id  = (string) ( $training['submission_id'] ?? '' );
		$fingerprint = (string) ( $training['source_fingerprint'] ?? '' );
		$employee_id = (int) ( $training['employee_user_id'] ?? 0 );
		$training_id = (int) ( $training['id'] ?? 0 );
		if ( 'manual' !== (string) ( $training['form_id'] ?? '' ) || $employee_id <= 0 || $training_id <= 0 || ! preg_match( '/^[a-f0-9]{32}$/', $capture_id ) || ! preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) || 0 !== strpos( $pdf, '%PDF-' ) || strlen( $pdf ) > self::MAX_NORMALIZED_BYTES ) {
			return new WP_Error( 'ghca_manual_training_invalid', __( 'The manual training record is not ready for certificate storage.', 'ghca-acd' ) );
		}
		$relative = self::relative_path( $employee_id, $training_id, $fingerprint, $capture_id, 0 );
		$written  = self::encrypt_and_write( $relative, $pdf, self::aad( $training, 0 ) );
		if ( is_wp_error( $written ) ) {
			return $written;
		}
		$file_name = substr( sanitize_file_name( $name ), 0, 255 );
		return array(
			array(
				'file_index'           => 0,
				'file_name'            => $file_name,
				'media_type'           => 'application/pdf',
				'bytes'                => strlen( $pdf ),
				'sha256'               => hash( 'sha256', $pdf ),
				'storage_path'         => $relative,
				'expected_source_name' => $file_name,
				'drive_source_name'    => '',
			),
		);
	}

	/**
	 * Re-verifies every stored manifest item (decrypt, digest, PDF prefix, byte count) without
	 * writing plaintext anywhere. Manual-entry preflight and approval both use this.
	 *
	 * @param array<string,mixed>            $training
	 * @param array<int,array<string,mixed>> $manifest
	 * @return true|WP_Error
	 */
	public static function verify_stored_manifest( array $training, array $manifest ) {
		if ( empty( $manifest ) ) {
			return new WP_Error( 'ghca_evidence_missing', __( 'No stored certificate is attached to this manual entry.', 'ghca-acd' ) );
		}
		foreach ( $manifest as $item ) {
			if ( ! is_array( $item ) ) {
				return new WP_Error( 'ghca_evidence_path_invalid', __( 'Stored evidence has an invalid manifest entry.', 'ghca-acd' ) );
			}
			$plain = self::decrypt_item( $training, $item );
			if ( is_wp_error( $plain ) ) {
				return $plain;
			}
			unset( $plain );
		}
		return true;
	}

	/** Reviewer-triggered compatibility check. It deliberately stores no remote bytes or status. */
	public static function preflight( array $training, array $form ) {
		$submission = self::current_submission( $training, $form );
		if ( is_wp_error( $submission ) ) { return $submission; }
		$descriptors = self::resolve_source_descriptors( $form, $submission );
		if ( is_wp_error( $descriptors ) ) { return $descriptors; }
		foreach ( $descriptors as $descriptor ) {
			$fetched = self::fetch_source_descriptor( $descriptor );
			if ( is_wp_error( $fetched ) ) { return $fetched; }
			$normalized = self::normalize_to_pdf( (string) $fetched['body'], (string) $fetched['mime'], (string) $fetched['name'] );
			if ( is_wp_error( $normalized ) ) { return $normalized; }
		}
		return true;
	}

	/** @return array<string,mixed>|WP_Error */
	private static function current_submission( array $training, array $form ) {
		$response = GHCA_ACD_Jotform_Provider::get_submission( (string) $training['submission_id'] );
		if ( is_wp_error( $response ) || ! is_array( $response['content'] ?? null ) ) { return new WP_Error( 'ghca_evidence_submission_unavailable', __( 'The submission could not be retrieved for PDF preflight.', 'ghca-acd' ) ); }
		$submission = $response['content'];
		$fingerprint = GHCA_ACD_Jotform_Sync::submission_fingerprint( $form, $submission );
		if ( ! hash_equals( (string) ( $training['source_fingerprint'] ?? '' ), $fingerprint ) ) { return new WP_Error( 'ghca_evidence_source_changed', __( 'The Jotform submission changed after it was indexed. Synchronize and review the new revision.', 'ghca-acd' ) ); }
		return $submission;
	}

	/**
	 * Resolves current remote source metadata. Both reviewer access and approval use this
	 * boundary so a Drive form cannot bypass Jotform metadata/ownership checks.
	 *
	 * @param array<string,string> $form
	 * @param array<string,mixed>  $submission
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public static function resolve_source_descriptors( array $form, array $submission ) {
		if ( 'google_drive' === (string) ( $form['file_source'] ?? 'jotform' ) ) {
			return GHCA_ACD_Google_Drive_Provider::files_for_submission( $form, $submission );
		}
		$file_answer = GHCA_ACD_Jotform_Provider::answer( $submission, (string) ( $form['certificate_qid'] ?? '' ) );
		$candidates = GHCA_ACD_Jotform_Provider::file_candidates( $file_answer );
		$urls = GHCA_ACD_Jotform_Provider::file_urls( $file_answer );
		if ( empty( $urls ) ) {
			return new WP_Error( 'ghca_evidence_missing', __( 'No valid certificate is attached to this submission.', 'ghca-acd' ) );
		}
		if ( count( $candidates ) !== count( $urls ) ) {
			return new WP_Error( 'ghca_evidence_file_invalid', __( 'One or more required certificate files has an invalid source location.', 'ghca-acd' ) );
		}
		return array_map( static function ( string $url ): array { return array( 'source' => 'jotform', 'url' => $url ); }, $urls );
	}

	/** @param array<string,string> $form @param array<string,mixed> $submission @return array{body:string,mime:string,name:string,bytes:int}|WP_Error */
	public static function resolve_source_file( array $form, array $submission, int $index ) {
		$descriptors = self::resolve_source_descriptors( $form, $submission );
		if ( is_wp_error( $descriptors ) ) {
			return $descriptors;
		}
		return isset( $descriptors[ $index ] ) ? self::fetch_source_descriptor( $descriptors[ $index ] ) : new WP_Error( 'ghca_evidence_file_missing', __( 'The requested certificate is unavailable.', 'ghca-acd' ) );
	}

	/** @param array<string,mixed> $descriptor @return array{body:string,mime:string,name:string,bytes:int}|WP_Error */
	private static function fetch_source_descriptor( array $descriptor ) {
		if ( 'jotform' === (string) ( $descriptor['source'] ?? '' ) && isset( $descriptor['url'] ) ) {
			return GHCA_ACD_Jotform_Provider::fetch_file( (string) $descriptor['url'] );
		}
		return GHCA_ACD_Google_Drive_Provider::fetch_file( $descriptor );
	}

	/** @param array<string,mixed> $training @param array<string,mixed> $item @return string|WP_Error */
	public static function materialize( array $training, array $item, string $destination ) {
		$plain = self::decrypt_item( $training, $item );
		if ( is_wp_error( $plain ) ) {
			return $plain;
		}
		if ( false === file_put_contents( $destination, $plain, LOCK_EX ) || ! GHCA_Audit_PDF_Jobs::secure_file( $destination ) ) {
			return new WP_Error( 'ghca_evidence_materialize_failed', __( 'Approved evidence could not be prepared.', 'ghca-acd' ) );
		}
		return $destination;
	}

	/**
	 * Returns one stored certificate as verified plaintext for streaming to an authorized
	 * reviewer. Nothing is written to disk: the caller echoes the bytes and they are freed
	 * with the request. Every integrity check of the packet path applies here too.
	 *
	 * @param array<string,mixed> $training @param array<string,mixed> $item
	 * @return string|WP_Error
	 */
	public static function read_stored_evidence( array $training, array $item ) {
		return self::decrypt_item( $training, $item );
	}

	/**
	 * Decrypts one stored manifest item and enforces every integrity invariant: strict path,
	 * envelope magic, AAD-bound GCM tag, size cap, SHA-256, PDF prefix and exact byte count.
	 *
	 * @param array<string,mixed> $training @param array<string,mixed> $item
	 * @return string|WP_Error Verified plaintext PDF.
	 */
	private static function decrypt_item( array $training, array $item ) {
		$base = self::base_directory();
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$relative = (string) ( $item['storage_path'] ?? '' );
		if ( ! preg_match( '#^external-evidence/[a-f0-9]{16}/[0-9]+/[0-9]+/[a-f0-9]{64}-[a-f0-9]{32}-[0-9]+\.ghca$#', $relative ) ) {
			return new WP_Error( 'ghca_evidence_path_invalid', __( 'Approved evidence has an invalid storage reference.', 'ghca-acd' ) );
		}
		$path = wp_normalize_path( $base . '/' . $relative );
		if ( ! is_file( $path ) ) {
			return new WP_Error( 'ghca_evidence_file_missing', __( 'Approved evidence is missing.', 'ghca-acd' ) );
		}
		$encrypted = file_get_contents( $path );
		if ( false === $encrypted || strlen( $encrypted ) < 39 || 0 !== strpos( $encrypted, self::MAGIC ) ) {
			return new WP_Error( 'ghca_evidence_file_corrupt', __( 'Approved evidence is unreadable.', 'ghca-acd' ) );
		}
		$iv = substr( $encrypted, 6, 12 );
		$tag = substr( $encrypted, 18, 16 );
		$ciphertext = substr( $encrypted, 34 );
		$key = self::key();
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$plain = openssl_decrypt( $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, self::aad( $training, (int) ( $item['file_index'] ?? 0 ) ) );
		if ( false === $plain || strlen( $plain ) > self::MAX_NORMALIZED_BYTES || ! hash_equals( (string) ( $item['sha256'] ?? '' ), hash( 'sha256', $plain ) ) || 0 !== strpos( $plain, '%PDF-' ) || strlen( $plain ) !== (int) ( $item['bytes'] ?? -1 ) ) {
			return new WP_Error( 'ghca_evidence_digest_failed', __( 'Approved evidence failed its integrity check.', 'ghca-acd' ) );
		}
		return $plain;
	}

	/** @param array<string,mixed> $item */
	public static function manual_manifest_is_valid( array $item ): bool {
		$base = self::base_directory();
		$key = self::key();
		$relative = (string) ( $item['storage_path'] ?? '' );
		if ( is_wp_error( $base ) || is_wp_error( $key ) || ! preg_match( '#^oltl-evidence/[a-f0-9]{16}/[0-9]+/[a-f0-9]{32}\.ghca$#', $relative ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $item['sha256'] ?? '' ) ) ) {
			return false;
		}
		$encrypted = @file_get_contents( wp_normalize_path( $base . '/' . $relative ) );
		if ( ! is_string( $encrypted ) || strlen( $encrypted ) < 39 || 0 !== strpos( $encrypted, self::MAGIC ) ) {
			return false;
		}
		$plain = openssl_decrypt( substr( $encrypted, 34 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $encrypted, 6, 12 ), substr( $encrypted, 18, 16 ), (string) ( $item['aad'] ?? '' ) );
		return is_string( $plain ) && strlen( $plain ) === (int) ( $item['bytes'] ?? -1 ) && strlen( $plain ) <= self::MAX_NORMALIZED_BYTES && 0 === strpos( $plain, '%PDF-' ) && hash_equals( (string) $item['sha256'], hash( 'sha256', $plain ) );
	}

	/** @return string|WP_Error */
	private static function normalize_to_pdf( string $body, string $claimed_mime, string $name ) {
		if ( '' === $body || strlen( $body ) > GHCA_ACD_Jotform_Provider::MAX_DOCUMENT_BYTES ) {
			return new WP_Error( 'ghca_evidence_size_invalid', __( 'Certificate evidence is empty or oversized.', 'ghca-acd' ) );
		}
		$detected = self::detect_type( $body );
		$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		$expected = array( 'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png' )[ $extension ] ?? '';
		if ( '' === $detected || ( '' !== $expected && $expected !== $detected ) || ( '' !== $claimed_mime && 'application/octet-stream' !== $claimed_mime && $claimed_mime !== $detected ) ) {
			return new WP_Error( 'ghca_evidence_mime_invalid', __( 'Certificate evidence failed file-type validation.', 'ghca-acd' ) );
		}
		$libs = GHCA_Audit_PDF::load_libs();
		if ( is_wp_error( $libs ) ) {
			return $libs;
		}
		if ( 'application/pdf' === $detected ) {
			$temp = wp_tempnam( 'ghca-evidence.pdf' );
			if ( ! $temp || false === file_put_contents( $temp, $body, LOCK_EX ) ) {
				return new WP_Error( 'ghca_evidence_validation_failed', __( 'Certificate evidence could not be validated.', 'ghca-acd' ) );
			}
			try {
				\setasign\Fpdi\PdfParser\Filter\Flate::resetDecodedBudget();
				$probe = new \setasign\Fpdi\Tcpdf\Fpdi();
				$pages = $probe->setSourceFile( $temp );
				if ( $pages < 1 || $pages > 100 ) {
					return new WP_Error( 'ghca_evidence_pages_invalid', __( 'Certificate evidence has an invalid page count.', 'ghca-acd' ) );
				}
			} catch ( Throwable $e ) {
				return new WP_Error( 'ghca_evidence_pdf_invalid', self::unreadable_pdf_message( $e ) );
			} finally {
				@unlink( $temp );
			}
			return strlen( $body ) <= self::MAX_NORMALIZED_BYTES ? $body : new WP_Error( 'ghca_evidence_normalized_size', __( 'Normalized evidence is oversized.', 'ghca-acd' ) );
		}
		$image = wp_tempnam( 'ghca-evidence-image' );
		if ( ! $image || false === file_put_contents( $image, $body, LOCK_EX ) ) {
			return new WP_Error( 'ghca_evidence_image_failed', __( 'Certificate image could not be prepared.', 'ghca-acd' ) );
		}
		try {
			$size = @getimagesize( $image );
			if ( ! is_array( $size ) || empty( $size[0] ) || empty( $size[1] ) || (int) $size[0] > 8000 || (int) $size[1] > 8000 || ( (int) $size[0] * (int) $size[1] ) > 20000000 ) {
				return new WP_Error( 'ghca_evidence_image_invalid', __( 'Certificate image dimensions are invalid.', 'ghca-acd' ) );
			}
			$orientation = $size[0] > $size[1] ? 'L' : 'P';
			$pdf = new TCPDF( $orientation, 'mm', 'LETTER', true, 'UTF-8', false );
			$pdf->setPrintHeader( false );
			$pdf->setPrintFooter( false );
			$pdf->SetMargins( 8, 8, 8 );
			$pdf->AddPage();
			$pdf->Image( $image, 8, 8, 0, 0, 'image/png' === $detected ? 'PNG' : 'JPG', '', '', false, 300, '', false, false, 0, true, false, false );
			$output = $pdf->Output( '', 'S' );
			return is_string( $output ) && strlen( $output ) <= self::MAX_NORMALIZED_BYTES ? $output : new WP_Error( 'ghca_evidence_normalized_size', __( 'Normalized evidence is oversized.', 'ghca-acd' ) );
		} finally {
			@unlink( $image );
		}
	}

	/**
	 * Explains why a PDF could not be parsed, so the uploader can act on it.
	 *
	 * The free FPDI parser cannot read compressed cross-reference streams or encrypted
	 * documents. Both are rejected on purpose: a file the parser cannot open here would
	 * also fail later during packet merge, so failing at capture keeps packets whole.
	 * Re-saving through Print to PDF rewrites the file into a form that merges cleanly.
	 */
	private static function unreadable_pdf_message( Throwable $error ): string {
		if ( $error instanceof \setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException ) {
			if ( \setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException::COMPRESSED_XREF === $error->getCode() ) {
				return __( 'This PDF uses a compressed cross-reference format that the packet builder cannot read. Open the certificate, use Print to PDF or Save as PDF to make a new copy, then upload that copy.', 'ghca-acd' );
			}
			if ( \setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException::ENCRYPTED === $error->getCode() ) {
				return __( 'This PDF is encrypted or password-protected, so it cannot be merged into an audit packet. Remove the protection or use Print to PDF to make an unprotected copy, then upload that copy.', 'ghca-acd' );
			}
		}
		return __( 'Certificate evidence is not a readable PDF.', 'ghca-acd' );
	}

	private static function detect_type( string $body ): string {
		if ( 0 === strpos( $body, '%PDF-' ) ) {
			return 'application/pdf';
		}
		if ( 0 === strpos( $body, "\xFF\xD8\xFF" ) ) {
			return 'image/jpeg';
		}
		if ( 0 === strpos( $body, "\x89PNG\r\n\x1a\n" ) ) {
			return 'image/png';
		}
		return '';
	}

	/** @return string|WP_Error */
	private static function key() {
		if ( ! defined( 'GHCA_ACD_EVIDENCE_ENCRYPTION_KEY' ) || ! is_string( GHCA_ACD_EVIDENCE_ENCRYPTION_KEY ) || strlen( trim( GHCA_ACD_EVIDENCE_ENCRYPTION_KEY ) ) < 32 ) {
			return new WP_Error( 'ghca_evidence_key_missing', __( 'GHCA_ACD_EVIDENCE_ENCRYPTION_KEY must be a stable secret of at least 32 characters.', 'ghca-acd' ) );
		}
		return hash( 'sha256', GHCA_ACD_EVIDENCE_ENCRYPTION_KEY, true );
	}

	/** @return string|WP_Error */
	public static function base_directory() {
		if ( ! defined( 'GHCA_ACD_PRIVATE_DIR' ) || ! is_string( GHCA_ACD_PRIVATE_DIR ) || '' === trim( GHCA_ACD_PRIVATE_DIR ) ) {
			return new WP_Error( 'ghca_evidence_private_dir_missing', __( 'GHCA_ACD_PRIVATE_DIR must be explicitly configured for approved evidence.', 'ghca-acd' ) );
		}
		$base = realpath( GHCA_ACD_PRIVATE_DIR );
		if ( false === $base || ! is_dir( $base ) || ! is_writable( $base ) ) {
			return new WP_Error( 'ghca_evidence_private_dir_unavailable', __( 'The configured private evidence directory is unavailable.', 'ghca-acd' ) );
		}
		$base = rtrim( wp_normalize_path( $base ), '/' );
		$blocked = array( realpath( ABSPATH ) );
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['basedir'] ) ) {
			$blocked[] = realpath( $uploads['basedir'] );
		}
		foreach ( $blocked as $root ) {
			if ( false !== $root && self::path_is_within( $base, wp_normalize_path( $root ) ) ) {
				return new WP_Error( 'ghca_evidence_private_dir_unsafe', __( 'Approved evidence storage must be outside WordPress and uploads.', 'ghca-acd' ) );
			}
		}
		if ( '\\' === DIRECTORY_SEPARATOR && ( ! defined( 'GHCA_ACD_PRIVATE_DIR_ACL_VERIFIED' ) || true !== GHCA_ACD_PRIVATE_DIR_ACL_VERIFIED ) ) {
			return new WP_Error( 'ghca_evidence_acl_unverified', __( 'The Windows private-directory ACL must be verified before evidence approval.', 'ghca-acd' ) );
		}
		return $base;
	}

	/** @return true|WP_Error */
	private static function encrypt_and_write( string $relative, string $plain, string $aad ) {
		$base = self::base_directory();
		$key = self::key();
		if ( is_wp_error( $base ) || is_wp_error( $key ) ) {
			return is_wp_error( $base ) ? $base : $key;
		}
		$path = wp_normalize_path( $base . '/' . $relative );
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'ghca_evidence_directory_failed', __( 'Approved evidence storage could not be created.', 'ghca-acd' ) );
		}
		@chmod( $dir, 0700 );
		$iv = random_bytes( 12 );
		$tag = '';
		$ciphertext = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16 );
		if ( false === $ciphertext || 16 !== strlen( $tag ) ) {
			return new WP_Error( 'ghca_evidence_encrypt_failed', __( 'Approved evidence encryption failed.', 'ghca-acd' ) );
		}
		if ( false === file_put_contents( $path, self::MAGIC . $iv . $tag . $ciphertext, LOCK_EX ) || ! GHCA_Audit_PDF_Jobs::secure_file( $path ) ) {
			@unlink( $path );
			return new WP_Error( 'ghca_evidence_write_failed', __( 'Approved evidence could not be stored.', 'ghca-acd' ) );
		}
		return true;
	}

	private static function relative_path( int $employee_id, int $training_id, string $fingerprint, string $capture_id, int $index ): string {
		$site = substr( hash( 'sha256', wp_normalize_path( ABSPATH ) ), 0, 16 );
		return sprintf( 'external-evidence/%s/%d/%d/%s-%s-%d.ghca', $site, $employee_id, $training_id, $fingerprint, $capture_id, $index );
	}

	/** @param array<string,mixed> $training */
	private static function aad( array $training, int $index ): string {
		return 'ghca-evidence-v1|' . (int) $training['employee_user_id'] . '|' . (int) $training['id'] . '|' . (string) $training['source_fingerprint'] . '|' . $index;
	}

	/** @param array<int,string> $relative_paths */
	public static function remove_files( array $relative_paths ): void {
		$base = self::base_directory();
		if ( is_wp_error( $base ) ) {
			return;
		}
		foreach ( $relative_paths as $relative ) {
			if ( preg_match( '#^external-evidence/[a-f0-9]{16}/[0-9]+/[0-9]+/[a-f0-9]{64}-[a-f0-9]{32}-[0-9]+\.ghca$#', $relative ) ) {
				@unlink( wp_normalize_path( $base . '/' . $relative ) );
			}
		}
	}

	public static function remove_manual_file( string $relative ): void {
		$base = self::base_directory();
		if ( ! is_wp_error( $base ) && preg_match( '#^oltl-evidence/[a-f0-9]{16}/[0-9]+/[a-f0-9]{32}\.ghca$#', $relative ) ) {
			@unlink( wp_normalize_path( $base . '/' . $relative ) );
		}
	}

	private static function path_is_within( string $path, string $root ): bool {
		$path = rtrim( wp_normalize_path( $path ), '/' ) . '/';
		$root = rtrim( wp_normalize_path( $root ), '/' ) . '/';
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$path = strtolower( $path );
			$root = strtolower( $root );
		}
		return 0 === strpos( $path, $root );
	}
}
