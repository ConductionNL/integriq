<?php

/**
 * Integriq CertificateUpload.
 *
 * An accepted Berichtenbox certificate upload: the authentication block with
 * the encrypted mTLS material, the certificate reference and the warnings.
 *
 * @category Service
 * @package  OCA\Integriq\Service\DigitalPost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\DigitalPost;

/**
 * Value object for one accepted certificate upload (ADR-011).
 *
 * @spec openspec/specs/digital-post-adapter/spec.md#requirement-the-transport-certificate-is-held-encrypted-and-named-by-reference-req-dpa-004
 */
final class CertificateUpload {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $authentication The authentication block, mode mtls.
	 * @param string               $certificateRef The certificate's sha256 fingerprint reference.
	 * @param list<string>         $warnings       Warnings for the administrator.
	 */
	public function __construct(
		public readonly array $authentication,
		public readonly string $certificateRef,
		public readonly array $warnings,
	) {
	}//end __construct()
}//end class
