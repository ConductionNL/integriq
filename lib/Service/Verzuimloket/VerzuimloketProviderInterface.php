<?php

/**
 * Integriq DUO Verzuimloket Provider Interface.
 *
 * Narrow domain seam through which every outbound DUO Verzuimloket
 * (VSV-M2M) meldingType envelope is dispatched. A new Edukoppeling-fronted
 * endpoint, or a compatible alternative transport, is added by implementing
 * this interface, never by editing VerzuimloketService or
 * VerzuimloketController — mirrors RodProviderInterface.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Verzuimloket
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
 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#requirement-req-001-verzuimloket-provider-abstraction-with-log-and-edukoppeling-bindings
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Verzuimloket;

use OCA\Integriq\Exception\VerzuimloketProviderException;

/**
 * A Verzuimloket transport binding: dispatch one already-translated
 * meldingType envelope and report the transport-assigned reference.
 *
 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#requirement-req-001-verzuimloket-provider-abstraction-with-log-and-edukoppeling-bindings
 */
interface VerzuimloketProviderInterface {
	/**
	 * Stable machine identifier for this binding (e.g. `log`, `edukoppeling`).
	 *
	 * @return string The provider identifier.
	 *
	 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#requirement-req-001-verzuimloket-provider-abstraction-with-log-and-edukoppeling-bindings
	 */
	public function getProviderId(): string;

	/**
	 * The JSON Schema describing this provider's `configuration` object.
	 *
	 * @return array<string, mixed> A JSON Schema (object) fragment.
	 *
	 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#requirement-req-001-verzuimloket-provider-abstraction-with-log-and-edukoppeling-bindings
	 */
	public function getConfigSchema(): array;

	/**
	 * Dispatch one already-translated meldingType envelope.
	 *
	 * @param array $sourceConfiguration The Verzuimloket source's `configuration` object.
	 * @param string $meldingType The melding kind being sent (`eerste-melding`|`herhaalmelding`|
	 *                            `langdurig-relatief-verzuim`).
	 * @param string $kenmerk The caller-supplied correlation id (echoed back on the retour leg).
	 * @param string $envelopeXml The fully rendered Edukoppeling envelope.
	 *
	 * @return string The transport-assigned reference.
	 *
	 * @throws VerzuimloketProviderException When the endpoint is unreachable, errors, or is misconfigured.
	 *
	 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#requirement-req-001-verzuimloket-provider-abstraction-with-log-and-edukoppeling-bindings
	 */
	public function send(array $sourceConfiguration, string $meldingType, string $kenmerk, string $envelopeXml): string;
}//end interface
