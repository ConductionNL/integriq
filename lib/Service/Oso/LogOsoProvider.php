<?php

/**
 * Integriq Log OSO Provider.
 *
 * Sandbox/mock binding for OsoProviderInterface: performs no real network
 * call and returns a synthetic `MOCK-OSO-<n>` reference. It MUST NOT read
 * any secret. It is the default for dev/CI (mirrors LogRodProvider).
 *
 * @category Service
 * @package  OCA\Integriq\Service\Oso
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
 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#scenario-the-log-provider-sends-nothing-over-the-network-and-returns-a-synthetic-ref
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Oso;

/**
 * Sandbox OSO provider: no network call, synthetic reference.
 *
 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#scenario-the-log-provider-sends-nothing-over-the-network-and-returns-a-synthetic-ref
 */
class LogOsoProvider implements OsoProviderInterface {

	/**
	 * Per-process counter for synthetic references (`MOCK-OSO-<n>`).
	 *
	 * @var integer
	 */
	private static int $counter = 0;

	/**
	 * {@inheritDoc}
	 *
	 * @return string The stable `log` provider identifier.
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-001-oso-export-provider-abstraction-with-log-and-kennisnet-bindings
	 */
	public function getProviderId(): string {
		return 'log';
	}//end getProviderId()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed> An empty schema — the log provider needs no configuration.
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-001-oso-export-provider-abstraction-with-log-and-kennisnet-bindings
	 */
	public function getConfigSchema(): array {
		return ['type' => 'object', 'properties' => []];
	}//end getConfigSchema()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration Unused.
	 * @param string $kenmerk Unused.
	 * @param string $envelopeXml Unused.
	 *
	 * @return string The synthetic `MOCK-OSO-<n>` reference.
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#scenario-the-log-provider-sends-nothing-over-the-network-and-returns-a-synthetic-ref
	 */
	public function sendExport(array $sourceConfiguration, string $kenmerk, string $envelopeXml): string {
		self::$counter++;
		return 'MOCK-OSO-' . self::$counter;
	}//end sendExport()
}//end class
