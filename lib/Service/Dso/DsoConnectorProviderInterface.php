<?php

/**
 * OpenConnector DSO Connector Provider Interface.
 *
 * Narrow domain seam through which every outbound DSO (Digitaal Stelsel
 * Omgevingswet) message — a `status` (voortgangsinformatie) or `besluit`
 * update on a previously received Verzoek — is dispatched back to DSO-LV. A
 * new DSO-LV-fronted endpoint, or a compatible alternative transport (e.g.
 * one adding PKIoverheid client-certificate auth), is added by implementing
 * this interface, never by editing {@see \OCA\OpenConnector\Service\DsoIngestService}
 * or {@see \OCA\OpenConnector\Controller\DSOController} — mirrors
 * `IwmoIjwProviderInterface` / `KlantinteractiesProviderInterface` /
 * `FscConnectivityProviderInterface` (see design.md "Provider seam,
 * credential storage, feature gating").
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Dso
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-dso-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Dso;

use OCA\OpenConnector\Exception\DsoProviderException;

/**
 * A DSO outbound transport binding: dispatch one already-built status/besluit
 * payload and report the transport-assigned reference.
 *
 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-dso-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
 */
interface DsoConnectorProviderInterface {
	/**
	 * Stable machine identifier for this binding (e.g. `log`, `rest`).
	 *
	 * Selected at runtime via the `dso` source's `configuration.provider`
	 * field — see {@see \OCA\OpenConnector\Service\DsoIngestService::resolveProvider()}.
	 *
	 * @return string The provider identifier.
	 *
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-dso-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
	 */
	public function getProviderId(): string;

	/**
	 * The JSON Schema describing this provider's `configuration` object.
	 *
	 * @return array<string, mixed> A JSON Schema (object) fragment.
	 *
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-dso-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
	 */
	public function getConfigSchema(): array;

	/**
	 * Dispatch one already-built outbound DSO message.
	 *
	 * @param array $sourceConfiguration The `dso` source's `configuration` object.
	 * @param string $verzoekId The DSO `verzoekId` this message concerns.
	 * @param string $type The message kind: `status` or `besluit`.
	 * @param array $payload The already-built message payload (see
	 *                       design.md's outbound field table).
	 *
	 * @return string The transport-assigned reference (or a locally-derived reference when
	 *                the transport assigns none of its own).
	 *
	 * @throws DsoProviderException When the endpoint is unreachable, errors, or is misconfigured.
	 *
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-dso-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
	 */
	public function send(array $sourceConfiguration, string $verzoekId, string $type, array $payload): string;
}//end interface
