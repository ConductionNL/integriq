<?php

/**
 * OpenConnector StUF-ZKN Provider Interface.
 *
 * Narrow domain seam through which every outbound StUF-ZKN `zakLk01`
 * kennisgeving is dispatched to a subscribed legacy StUF consumer. A new
 * consumer endpoint, or a compatible alternative transport, is added by
 * implementing this interface, never by editing `StufZknSyncService` or
 * `StufZknController` — mirrors `IwmoIjwProviderInterface`/
 * `DsoConnectorProviderInterface` (see design.md "Provider seam, credential
 * storage, feature gating").
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\StufZkn
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
 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-stufzkn-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\StufZkn;

use OCA\OpenConnector\Exception\StufZknProviderException;

/**
 * A StUF-ZKN outbound transport binding: dispatch one already-translated
 * `zakLk01` envelope and report the transport-assigned/derived reference.
 *
 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-stufzkn-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
 */
interface StufZknProviderInterface {
	/**
	 * Stable machine identifier for this binding (e.g. `log`, `rest`).
	 *
	 * @return string The provider identifier.
	 *
	 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-stufzkn-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
	 */
	public function getProviderId(): string;

	/**
	 * The JSON Schema describing this provider's `configuration` object.
	 *
	 * @return array<string, mixed> A JSON Schema (object) fragment.
	 *
	 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-stufzkn-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
	 */
	public function getConfigSchema(): array;

	/**
	 * Dispatch one already-translated `zakLk01` kennisgeving envelope.
	 *
	 * @param array $sourceConfiguration The `stuf-zkn` source's `configuration` object.
	 * @param string $referentienummer The kennisgeving's `stuurgegevens.referentienummer`.
	 * @param string $envelopeXml The fully rendered envelope XML — the transport MUST send
	 *                            this verbatim as the request body, never re-serialize it.
	 *
	 * @return string The transport-assigned (or locally-derived) reference.
	 *
	 * @throws StufZknProviderException When the endpoint is unreachable, errors, or is misconfigured.
	 *
	 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-stufzkn-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
	 */
	public function send(array $sourceConfiguration, string $referentienummer, string $envelopeXml): string;
}//end interface
