<?php

/**
 * Integriq mTLS Materialized Files.
 *
 * Immutable record of the transient, `0600`-permission temp-file paths
 * {@see MtlsTransportOptionsBuilder::materialize()} created for one
 * request cycle, so {@see MtlsTransportOptionsBuilder::cleanup()} can
 * remove exactly those paths afterwards — success or failure.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Mtls
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#requirement-certificate-material-is-materialised-to-disk-only-transiently-with-guaranteed-cleanup-req-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Mtls;

/**
 * The set of temp-file paths materialized for one mTLS request cycle.
 *
 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#requirement-certificate-material-is-materialised-to-disk-only-transiently-with-guaranteed-cleanup-req-002
 */
final class MtlsMaterializedFiles {
	/**
	 * Constructor.
	 *
	 * @param string $certificatePath Path to the materialized certificate PEM.
	 * @param string $privateKeyPath Path to the materialized private key PEM.
	 * @param string|null $caBundlePath Path to the materialized CA bundle PEM, or null when none was configured.
	 */
	public function __construct(
		public readonly string $certificatePath,
		public readonly string $privateKeyPath,
		public readonly ?string $caBundlePath = null,
	) {

	}//end __construct()

	/**
	 * Every materialized path, for cleanup iteration.
	 *
	 * @return array<int, string|null>
	 *
	 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#requirement-certificate-material-is-materialised-to-disk-only-transiently-with-guaranteed-cleanup-req-002
	 */
	public function allPaths(): array {
		return [$this->certificatePath, $this->privateKeyPath, $this->caBundlePath];
	}//end allPaths()
}//end class
