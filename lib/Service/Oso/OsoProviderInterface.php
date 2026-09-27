<?php

/**
 * Integriq OSO Export Provider Interface.
 *
 * Narrow domain seam through which every outbound OSO overstapdossier
 * export envelope is dispatched. A new Kennisnet-fronted endpoint, or a
 * compatible alternative transport, is added by implementing this
 * interface, never by editing OsoService or OsoController — mirrors
 * RodProviderInterface. There is no import-side provider interface: an
 * inbound overstapdossier is Kennisnet-initiated (a push this app
 * receives), not something this adapter dispatches — see
 * OsoImportTranslator.
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
 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-001-oso-export-provider-abstraction-with-log-and-kennisnet-bindings
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Oso;

use OCA\Integriq\Exception\OsoProviderException;

/**
 * An OSO export transport binding: dispatch one already-translated
 * overstapdossier envelope and report the transport-assigned reference.
 *
 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-001-oso-export-provider-abstraction-with-log-and-kennisnet-bindings
 */
interface OsoProviderInterface {
	/**
	 * Stable machine identifier for this binding (e.g. `log`, `kennisnet`).
	 *
	 * @return string The provider identifier.
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-001-oso-export-provider-abstraction-with-log-and-kennisnet-bindings
	 */
	public function getProviderId(): string;

	/**
	 * The JSON Schema describing this provider's `configuration` object.
	 *
	 * @return array<string, mixed> A JSON Schema (object) fragment.
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-001-oso-export-provider-abstraction-with-log-and-kennisnet-bindings
	 */
	public function getConfigSchema(): array;

	/**
	 * Dispatch one already-translated export overstapdossier envelope.
	 *
	 * @param array $sourceConfiguration The OSO source's `configuration` object.
	 * @param string $kenmerk The caller-supplied correlation id.
	 * @param string $envelopeXml The fully rendered export envelope.
	 *
	 * @return string The transport-assigned reference.
	 *
	 * @throws OsoProviderException When the endpoint is unreachable, errors, or is misconfigured.
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-001-oso-export-provider-abstraction-with-log-and-kennisnet-bindings
	 */
	public function sendExport(array $sourceConfiguration, string $kenmerk, string $envelopeXml): string;
}//end interface
