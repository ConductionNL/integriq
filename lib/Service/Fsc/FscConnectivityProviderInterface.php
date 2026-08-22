<?php

/**
 * Integriq FSC Connectivity Provider Interface.
 *
 * Narrow domain seam through which every FSC (Federatieve Service
 * Connectiviteit — the VNG/Common Ground standard that replaced NLX in
 * 2025) directory resolution and downstream service call is dispatched. A
 * new Outway/Inway-backed transport (e.g. one adding client-certificate
 * mTLS auth), or a compatible alternative, is added by implementing this
 * interface, never by editing FscCallService or FscController — mirrors
 * IwmoIjwProviderInterface / KlantinteractiesProviderInterface /
 * SmsProviderInterface (see design.md "Provider seam, credential storage,
 * feature gating").
 *
 * @category Service
 * @package  OCA\Integriq\Service\Fsc
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
 * @spec openspec/specs/fsc-connectivity/spec.md#requirement-fsc-provider-abstraction-with-log-and-rest-bindings-req-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Fsc;

use OCA\Integriq\Exception\FscConnectivityException;
use OCA\Integriq\Exception\FscDirectoryException;

/**
 * An FSC transport binding: resolve one organisation+service against the
 * directory, then dispatch one call against the resolved endpoint.
 *
 * @spec openspec/specs/fsc-connectivity/spec.md#requirement-fsc-provider-abstraction-with-log-and-rest-bindings-req-001
 */
interface FscConnectivityProviderInterface {
	/**
	 * Stable machine identifier for this binding (e.g. `log`, `rest`).
	 *
	 * Selected at runtime via the FSC source's `configuration.provider`
	 * field — see {@see \OCA\Integriq\Service\FscCallService::resolveProvider()}.
	 *
	 * @return string The provider identifier.
	 *
	 * @spec openspec/specs/fsc-connectivity/spec.md#requirement-fsc-provider-abstraction-with-log-and-rest-bindings-req-001
	 */
	public function getProviderId(): string;

	/**
	 * The JSON Schema describing this provider's `configuration` object.
	 *
	 * @return array<string, mixed> A JSON Schema (object) fragment.
	 *
	 * @spec openspec/specs/fsc-connectivity/spec.md#requirement-fsc-provider-abstraction-with-log-and-rest-bindings-req-001
	 */
	public function getConfigSchema(): array;

	/**
	 * Resolve an organisation+service pair to a routable endpoint and
	 * required auth context via the configured directory.
	 *
	 * @param array $directoryConfig The FSC source's `configuration.directory` object.
	 * @param string $organisation The target organisation identifier (e.g. an OIN).
	 * @param string $service The target service identifier, scoped to that organisation.
	 *
	 * Returns the resolution: a routable endpoint plus any auth context
	 * `call()` needs.
	 *
	 * @return array{organisation: string, service: string, endpoint: string, grantRequired: bool, authContext: array<string, mixed>}
	 *
	 * @throws FscDirectoryException When the organisation or service is not known to the directory.
	 * @throws FscConnectivityException When the directory itself is unreachable, errors, or is misconfigured.
	 *
	 * @spec openspec/specs/fsc-connectivity/spec.md#requirement-directory-resolution-req-002
	 */
	public function resolveService(array $directoryConfig, string $organisation, string $service): array;

	/**
	 * Dispatch one call against an already-resolved endpoint.
	 *
	 * @param array $directoryConfig The FSC source's `configuration.directory` object.
	 * @param array $resolution The resolution returned by {@see resolveService()}.
	 * @param string $method The HTTP-style method to invoke (`GET`, `POST`, ...).
	 * @param array $payload The call payload — passed through largely as-is
	 *                       (FSC's resolve-then-call shape has no VNG-style
	 *                       translation step).
	 *
	 * @return array{ref: string, statusCode: int, body: mixed} The transport outcome.
	 *
	 * @throws FscConnectivityException When the endpoint is unreachable, errors, or is misconfigured.
	 *
	 * @spec openspec/specs/fsc-connectivity/spec.md#requirement-call-routing-through-the-provider-seam-req-003
	 */
	public function call(array $directoryConfig, array $resolution, string $method, array $payload): array;
}//end interface
