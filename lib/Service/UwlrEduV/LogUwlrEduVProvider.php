<?php

/**
 * Integriq Log UWLR/Edu-V Provider.
 *
 * Sandbox/mock binding for UwlrEduVProviderInterface: performs no real
 * network call and returns a synthetic `MOCK-UWLREDUV-<n>` reference. It
 * MUST NOT read any secret. It is the default for dev/CI (mirrors
 * LogOsoProvider).
 *
 * @category Service
 * @package  OCA\Integriq\Service\UwlrEduV
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
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#scenario-the-log-provider-sends-nothing-over-the-network-and-returns-a-synthetic-ref
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\UwlrEduV;

/**
 * Sandbox UWLR/Edu-V provider: no network call, synthetic reference.
 *
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#scenario-the-log-provider-sends-nothing-over-the-network-and-returns-a-synthetic-ref
 */
class LogUwlrEduVProvider implements UwlrEduVProviderInterface {

	/**
	 * Per-process counter for synthetic references (`MOCK-UWLREDUV-<n>`).
	 *
	 * @var integer
	 */
	private static int $counter = 0;

	/**
	 * {@inheritDoc}
	 *
	 * @return string The stable `log` provider identifier.
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-001-shared-provider-abstraction-with-log-and-uwlr-eduv-bindings
	 */
	public function getProviderId(): string {
		return 'log';
	}//end getProviderId()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed> An empty schema — the log provider needs no configuration.
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-001-shared-provider-abstraction-with-log-and-uwlr-eduv-bindings
	 */
	public function getConfigSchema(): array {
		return ['type' => 'object', 'properties' => []];
	}//end getConfigSchema()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration Unused.
	 * @param string $target Unused.
	 * @param string $kenmerk Unused.
	 * @param string $envelopeXml Unused.
	 *
	 * @return string The synthetic `MOCK-UWLREDUV-<n>` reference.
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#scenario-the-log-provider-sends-nothing-over-the-network-and-returns-a-synthetic-ref
	 */
	public function send(array $sourceConfiguration, string $target, string $kenmerk, string $envelopeXml): string {
		self::$counter++;
		return 'MOCK-UWLREDUV-' . self::$counter;
	}//end send()
}//end class
