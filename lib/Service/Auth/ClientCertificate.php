<?php

/**
 * Integriq ClientCertificate.
 *
 * One parsed client certificate: its PEM, SHA-256 fingerprint, subject and
 * issuer, validity and, for a PKIoverheid certificate, the OIN from the
 * subject serialNumber.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Auth
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-can-require-a-client-certificate-req-cred-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Auth;

/**
 * A parsed client certificate.
 *
 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-can-require-a-client-certificate-req-cred-004
 */
final class ClientCertificate {

	/**
	 * @param string      $pem         The certificate, PEM encoded.
	 * @param string      $fingerprint Lowercase hex SHA-256 of the DER bytes.
	 * @param string      $subject     The subject as one line, `/C=NL/O=.../serialNumber=...`.
	 * @param string      $issuer      The issuer as one line, in the same form.
	 * @param int         $validFrom   Start of validity, Unix time.
	 * @param int         $validTo     End of validity, Unix time.
	 * @param string|null $oin         The OIN from the subject serialNumber, when it is one.
	 */
	public function __construct(
		public readonly string $pem,
		public readonly string $fingerprint,
		public readonly string $subject,
		public readonly string $issuer,
		public readonly int $validFrom,
		public readonly int $validTo,
		public readonly ?string $oin = null,
	) {
	}//end __construct()

	/**
	 * Whether the certificate is valid at the given time.
	 *
	 * @param int $now Unix time.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-can-require-a-client-certificate-req-cred-004
	 */
	public function isValidAt(int $now): bool {
		return $now >= $this->validFrom && $now <= $this->validTo;
	}//end isValidAt()
}//end class
