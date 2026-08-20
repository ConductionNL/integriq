<?php

/**
 * OpenConnector mTLS Certificate Bundle.
 *
 * Immutable, in-memory-only value object carrying decrypted mutual-TLS
 * client-certificate material for the duration of a single resolve→dispatch
 * cycle. Never cached, never persisted, never logged — {@see
 * \OCA\OpenConnector\Service\Mtls\MtlsConfigResolver} produces it and {@see
 * \OCA\OpenConnector\Service\Mtls\MtlsTransportService} consumes it once.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Mtls
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#requirement-shared-mtls-transport-builds-guzzle-tls-options-from-encrypted-at-rest-certificate-material-req-001
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Mtls;

/**
 * Decrypted mTLS client-certificate material for one request cycle.
 *
 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#requirement-shared-mtls-transport-builds-guzzle-tls-options-from-encrypted-at-rest-certificate-material-req-001
 */
final class MtlsCertificateBundle {
	/**
	 * Constructor.
	 *
	 * @param string $certificatePem The client certificate, PEM-encoded.
	 * @param string $privateKeyPem The client private key, PEM-encoded.
	 * @param string|null $passphrase The private key passphrase, or null when unprotected.
	 * @param string|null $caBundlePem An optional custom CA bundle, PEM-encoded, to verify the peer against.
	 */
	public function __construct(
		public readonly string $certificatePem,
		public readonly string $privateKeyPem,
		public readonly ?string $passphrase = null,
		public readonly ?string $caBundlePem = null,
	) {

	}//end __construct()
}//end class
