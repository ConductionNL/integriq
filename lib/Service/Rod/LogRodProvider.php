<?php

/**
 * Integriq Log ROD Provider.
 *
 * Sandbox/mock binding for {@see RodProviderInterface}: performs no real
 * network call and returns a synthetic `MOCK-ROD-<n>` reference. It MUST
 * NOT read any secret. It is the default for dev/CI and mirrors the
 * LogIwmoIjwProvider / LogDigitalPostProvider sandbox convention.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Rod
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
 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#scenario-the-log-provider-sends-nothing-over-the-network-and-returns-a-synthetic-ref
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Rod;

/**
 * Sandbox ROD provider: no network call, synthetic reference.
 *
 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#scenario-the-log-provider-sends-nothing-over-the-network-and-returns-a-synthetic-ref
 */
class LogRodProvider implements RodProviderInterface {

	/**
	 * Per-process counter for synthetic references (`MOCK-ROD-<n>`).
	 *
	 * A per-process, in-memory counter is sufficient for a sandbox binding —
	 * refs only need to be locally unique for the duration of one
	 * request/job run (mirrors LogIwmoIjwProvider::$counter).
	 *
	 * @var integer
	 */
	private static int $counter = 0;

	/**
	 * {@inheritDoc}
	 *
	 * @return string The stable `log` provider identifier.
	 *
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#requirement-req-001-rod-provider-abstraction-with-log-and-edukoppeling-bindings
	 */
	public function getProviderId(): string {
		return 'log';
	}//end getProviderId()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed> An empty schema — the log provider needs no configuration.
	 *
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#requirement-req-001-rod-provider-abstraction-with-log-and-edukoppeling-bindings
	 */
	public function getConfigSchema(): array {
		return ['type' => 'object', 'properties' => []];
	}//end getConfigSchema()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration Unused — the log provider needs no configuration.
	 * @param string $berichtsoort Unused.
	 * @param string $kenmerk Unused.
	 * @param string $envelopeXml Unused.
	 *
	 * @return string The synthetic `MOCK-ROD-<n>` reference.
	 *
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#scenario-the-log-provider-sends-nothing-over-the-network-and-returns-a-synthetic-ref
	 */
	public function send(array $sourceConfiguration, string $berichtsoort, string $kenmerk, string $envelopeXml): string {
		self::$counter++;
		return 'MOCK-ROD-' . self::$counter;
	}//end send()
}//end class
