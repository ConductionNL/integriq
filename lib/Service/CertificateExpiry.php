<?php

/**
 * Integriq CertificateExpiry: where a source's client certificate is kept, when
 * it expires, where to renew it and, when the expiry is unknown, why.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/integriq
 *
 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/connection-run-monitoring/spec.md#requirement-a-source-shows-when-its-certificate-expires-req-crun-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * One certificate's expiry as ConnectionCertificateReader found it.
 *
 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/connection-run-monitoring/spec.md#requirement-a-source-shows-when-its-certificate-expires-req-crun-007
 */
final class CertificateExpiry {
	/**
	 * Where to renew it.
	 *
	 * @var string|null
	 */
	public ?string $renewUrl = null;

	/**
	 * Why the expiry is unknown.
	 *
	 * @var string|null
	 */
	public ?string $unknownReason = null;

	/**
	 * Constructor.
	 *
	 * @param string      $keptIn   `keepiq` or `source`.
	 * @param string|null $notAfter The expiry, ISO 8601.
	 */
	public function __construct(
		public readonly string $keptIn,
		public readonly ?string $notAfter,
	) {
	}//end __construct()
}//end class
