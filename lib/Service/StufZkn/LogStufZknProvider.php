<?php

/**
 * Integriq Log StUF-ZKN Provider.
 *
 * Sandbox/mock binding for {@see StufZknProviderInterface}: performs no
 * real network call and returns a synthetic `MOCK-STUFZKN-<n>` reference.
 * It MUST NOT read any secret. It is the default for dev/CI and mirrors
 * `LogIwmoIjwProvider`/`LogDsoConnectorProvider`'s sandbox convention.
 *
 * @category Service
 * @package  OCA\Integriq\Service\StufZkn
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
 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-stufzkn-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\StufZkn;

/**
 * Sandbox StUF-ZKN outbound provider: no network call, synthetic reference.
 *
 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-stufzkn-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
 */
class LogStufZknProvider implements StufZknProviderInterface {

	/**
	 * Per-process counter for synthetic references (`MOCK-STUFZKN-<n>`).
	 *
	 * @var integer
	 */
	private static int $counter = 0;

	/**
	 * {@inheritDoc}
	 *
	 * @return string The stable `log` provider identifier.
	 *
	 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-stufzkn-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
	 */
	public function getProviderId(): string {
		return 'log';
	}//end getProviderId()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed> An empty schema — the log provider needs no configuration.
	 *
	 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-stufzkn-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
	 */
	public function getConfigSchema(): array {
		return ['type' => 'object', 'properties' => []];
	}//end getConfigSchema()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration Unused — the log provider needs no configuration.
	 * @param string $referenceNumber Unused.
	 * @param string $envelopeXml Unused.
	 *
	 * @return string The synthetic `MOCK-STUFZKN-<n>` reference.
	 *
	 * @spec openspec/specs/stuf-zkn-bridge/spec.md#scenario-the-log-provider-sends-nothing-over-the-network-and-returns-a-synthetic-ref
	 */
	public function send(array $sourceConfiguration, string $referenceNumber, string $envelopeXml): string {
		self::$counter++;
		return 'MOCK-STUFZKN-' . self::$counter;
	}//end send()
}//end class
