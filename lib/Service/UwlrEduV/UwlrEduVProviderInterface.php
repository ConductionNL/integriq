<?php

/**
 * Integriq UWLR/Edu-V/Basispoort/Entree-content Provider Interface.
 *
 * Narrow domain seam through which every outbound UWLR, Edu-V, Basispoort
 * or Entree-content envelope is dispatched. A new Kennisnet-fronted
 * endpoint, or a compatible alternative transport, is added by
 * implementing this interface, never by editing UwlrEduVService or
 * UwlrEduVController — mirrors OsoProviderInterface. One interface serves
 * all four targets (see design.md "Trade-offs"): `send()` takes a
 * `target` discriminator so a live client can route to the right
 * downstream endpoint once real transport details exist.
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
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-001-shared-provider-abstraction-with-log-and-uwlr-eduv-bindings
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\UwlrEduV;

use OCA\Integriq\Exception\UwlrEduVProviderException;

/**
 * A UWLR/Edu-V/Basispoort/Entree-content transport binding: dispatch one
 * already-translated envelope for a given target and report the
 * transport-assigned reference.
 *
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-001-shared-provider-abstraction-with-log-and-uwlr-eduv-bindings
 */
interface UwlrEduVProviderInterface {
	/**
	 * Stable machine identifier for this binding (e.g. `log`, `uwlr-eduv`).
	 *
	 * @return string The provider identifier.
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-001-shared-provider-abstraction-with-log-and-uwlr-eduv-bindings
	 */
	public function getProviderId(): string;

	/**
	 * The JSON Schema describing this provider's `configuration` object.
	 *
	 * @return array<string, mixed> A JSON Schema (object) fragment.
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-001-shared-provider-abstraction-with-log-and-uwlr-eduv-bindings
	 */
	public function getConfigSchema(): array;

	/**
	 * Dispatch one already-translated envelope for a given target.
	 *
	 * @param array $sourceConfiguration The UWLR/Edu-V source's `configuration` object.
	 * @param string $target One of `uwlr`, `edu-v`, `basispoort`, `entree-content`.
	 * @param string $kenmerk The caller-supplied correlation id.
	 * @param string $envelopeXml The fully rendered envelope.
	 *
	 * @return string The transport-assigned reference.
	 *
	 * @throws UwlrEduVProviderException When the endpoint is unreachable, errors, or is misconfigured.
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-001-shared-provider-abstraction-with-log-and-uwlr-eduv-bindings
	 */
	public function send(array $sourceConfiguration, string $target, string $kenmerk, string $envelopeXml): string;
}//end interface
