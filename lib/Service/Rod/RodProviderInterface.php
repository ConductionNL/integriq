<?php

/**
 * Integriq DUO ROD Provider Interface.
 *
 * Narrow domain seam through which every outbound DUO ROD (Register
 * Onderwijsdeelnemers) berichtsoort envelope is dispatched. A new
 * Edukoppeling-fronted endpoint, or a compatible alternative transport, is
 * added by implementing this interface, never by editing RodService or
 * RodController — mirrors IwmoIjwProviderInterface / DigitalPostProviderInterface.
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
 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#requirement-req-001-rod-provider-abstraction-with-log-and-edukoppeling-bindings
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Rod;

use OCA\Integriq\Exception\RodProviderException;

/**
 * A ROD transport binding: dispatch one already-translated berichtsoort
 * envelope and report the transport-assigned reference.
 *
 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#requirement-req-001-rod-provider-abstraction-with-log-and-edukoppeling-bindings
 */
interface RodProviderInterface {
	/**
	 * Stable machine identifier for this binding (e.g. `log`, `edukoppeling`).
	 *
	 * Selected at runtime via the ROD source's `configuration.provider`
	 * field — see {@see \OCA\Integriq\Service\RodService::resolveProvider()}.
	 *
	 * @return string The provider identifier.
	 *
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#requirement-req-001-rod-provider-abstraction-with-log-and-edukoppeling-bindings
	 */
	public function getProviderId(): string;

	/**
	 * The JSON Schema describing this provider's `configuration` object.
	 *
	 * @return array<string, mixed> A JSON Schema (object) fragment.
	 *
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#requirement-req-001-rod-provider-abstraction-with-log-and-edukoppeling-bindings
	 */
	public function getConfigSchema(): array;

	/**
	 * Dispatch one already-translated berichtsoort envelope.
	 *
	 * @param array $sourceConfiguration The ROD source's `configuration` object.
	 * @param string $berichtsoort The ROD berichtsoort being sent (`inschrijving`|`uitschrijving`|
	 *                             `verblijfsgegevens`|`schooladvies`).
	 * @param string $kenmerk The caller-supplied correlation id (echoed back on the retour leg).
	 * @param string $envelopeXml The fully rendered Edukoppeling envelope — the transport MUST send
	 *                            this verbatim as the request body, never re-serialize it.
	 *
	 * @return string The transport-assigned reference (or the echoed `kenmerk` when the
	 *                transport assigns none of its own).
	 *
	 * @throws RodProviderException When the endpoint is unreachable, errors, or is misconfigured.
	 *
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#requirement-req-001-rod-provider-abstraction-with-log-and-edukoppeling-bindings
	 */
	public function send(array $sourceConfiguration, string $berichtsoort, string $kenmerk, string $envelopeXml): string;
}//end interface
